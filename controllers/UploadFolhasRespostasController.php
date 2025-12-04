<?php

namespace controllers;
use helpers\RabbitMQHelper;
use helpers\S3Helper;
use models\Coleta;
use models\forms\FormPacoteCorrecao;
use \PDO;
use helpers\RespostasCadernoHelper;

require_once __DIR__ . '/..\models\forms\FormPacoteCorrecao.php';
require_once __DIR__ . '/..\models\Coleta.php';
require_once __DIR__ . '/..\helpers\S3Helper.php';
require_once __DIR__ . '/..\helpers\GhostscriptHelper.php';
require_once __DIR__ . '/..\helpers\ZipHelper.php';
require_once __DIR__ . '/..\helpers\RabbitMQHelper.php';
require_once __DIR__ . '/..\helpers\uploadHelper.php';

final class UploadFolhasRespostasController
{
    public string $pgTitulo = 'Upload de arquivos com folhas de respostas';
    public string $pgSubtitulo = 'Permite fazer o upload de arquivos com folhas de respostas para correção';
    public string $menuAtivo = 'corretor';
    public string $submenuAtivo = '';

    private \PDO $db;

    public function __construct()
    {
        session_start();
        $this->db = new PDO(
            'mysql:host=localhost;port=23306;dbname=corretor_saeb;charset=utf8mb4',
            'root',
            'root',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function getDb(): PDO
    {
        return $this->db;
    }

    public function actionIndex(): void
    {
        $this->redirect('verFila');
    }

    /**
     * ✅ CORRIGIDO: Recebe nomeLote do form
     */
    public function actionUpload(): void
    {
        $this->submenuAtivo = 'novaCorrecao';
        $model = new FormPacoteCorrecao();
        $acceptMimeTypes = FormPacoteCorrecao::$mimeTypes;
        $coletas = Coleta::listarTodas();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model->coletaId = $_POST['FormPacoteCorrecao']['coletaId'] ?? null;
            $model->nomeLote = $_POST['FormPacoteCorrecao']['nomeLote'] ?? null;
            $model->arquivo = $_FILES['FormPacoteCorrecao_arquivo'] ?? null;
            $model->descricao = $_POST['FormPacoteCorrecao']['descricao'] ?? null;

            if ($model->validate()) {
                $loteSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $model->nomeLote);
                $zipTmp = $model->arquivo['tmp_name'];
                $zipName = $model->arquivo['name'];

                // 1) Valida extensão ZIP
                $ext = strtolower(pathinfo($zipName, PATHINFO_EXTENSION));
                if ($ext !== 'zip') {
                    $this->redirecionaComFlash('negative', 'Envie um arquivo ZIP.', ['upload']);
                    return;
                }

                // 2) Sobe ZIP bruto para S3
                $zipKey = "cliente/saeb/uploads/{$loteSafe}/{$zipName}";
                $okS3 = S3Helper::uploadFile($zipTmp, $zipKey);

                if (!$okS3) {
                    $this->redirecionaComFlash('negative', 'Falha S3.', ['upload']);
                    return;
                }

                // 3) ✅ REGISTRA LOTE E PEGA ID GERADO
                $pdo = $this->getDb();
                $agora = date('Y-m-d H:i:s');

                $stmt = $pdo->prepare("
                INSERT INTO lotes_correcao (nome, descricao, s3_prefix, total_arquivos, status, criado_em, atualizado_em)
                VALUES (:nome, :descricao, :s3_prefix, :total, 'uploaded_bruto', :criado_em, :atualizado_em)
                ON DUPLICATE KEY UPDATE
                    descricao = VALUES(descricao),
                    s3_prefix = VALUES(s3_prefix),
                    total_arquivos = VALUES(total_arquivos),
                    status = 'uploaded_bruto',
                    atualizado_em = VALUES(atualizado_em)
            ");

                $stmt->execute([
                    ':nome' => $model->nomeLote,
                    ':descricao' => $model->descricao,
                    ':s3_prefix' => $zipKey,
                    ':total' => 1,
                    ':criado_em' => $agora,
                    ':atualizado_em' => $agora,
                ]);

                // ✅ PEGA O ID DO LOTE (NOVO ou EXISTENTE)
                $loteIdNumerico = (int)$pdo->lastInsertId();

                // 4) ✅ ENVIA PARA NORMALIZER COM LOTE ID
                $callbackUrl = "http://" . $_SERVER['HTTP_HOST'] .
                    "/uploadFolhasRespostas/actionCallbackNormalizacao";

                $payload = [
                    'nomeLote' => $model->nomeLote,
                    'zipKey' => $zipKey,
                    'bucket' => 'dadoscorretor',
                    'callbackUrl' => $callbackUrl,           // ✅ Para normalizer avisar
                    'loteIdNumerico' => $loteIdNumerico     // ✅ ID para repassar ao Java
                ];

                RabbitMQHelper::enviarParaFila('normalizacao_queue', $payload);

                $this->redirecionaComFlash(
                    'success',
                    "Lote '{$model->nomeLote}' (ID: {$loteIdNumerico}) enviado para normalização.",
                    ['verFila']
                );
            }
        }

        $this->render('upload', compact('model', 'acceptMimeTypes', 'coletas'));
    }


    public function actionCallbackNormalizacao(): void
    {
        // Meu endpoint só aceita POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Método não permitido';
            return;
        }

        // Body esperado (JSON):
        // {
        //   "nomeLote": "Lote_123",
        //   "event": "started" | "finished" | "error",
        //   "normalizedPrefix": "normalizados/Lote_123/",
        //   "errorMessage": "opcional"
        // }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['nomeLote']) || empty($data['event'])) {
            http_response_code(400);
            echo 'Payload inválido';
            return;
        }

        $nomeLote = $data['nomeLote'];
        $event = $data['event'];

        $pdo = $this->getDb();

        try {
            switch ($event) {
                case 'started':
                    // normalizador avisou que começou a normalizar
                    $stmt = $pdo->prepare("
                    UPDATE lotes_correcao
                    SET status = 'normalizing', atualizado_em = NOW()
                    WHERE nome = :nome
                    ");
                    $stmt->execute([':nome' => $nomeLote]);
                    break;

                case 'finished':
                    // normalização concluída, já temos prefixo das imagens normalizadas
                    $normalizedPrefix = $data['normalizedPrefix'] ?? null;
                    $totalImagens = $data['totalImagens'] ?? 0;
                    if (!$normalizedPrefix) {
                        http_response_code(400);
                        echo 'normalizedPrefix obrigatório quando event=finished';
                        return;
                    }

                    $stmt = $pdo->prepare("
                        UPDATE lotes_correcao
                        SET status = 'normalized',
                        s3_prefix = :s3_prefix,
                        total_arquivos = :total_arquivos,
                        atualizado_em = NOW()
                        WHERE nome = :nome
                        ");
                    $stmt->execute([
                        ':nome' => $nomeLote,
                        ':s3_prefix' => $normalizedPrefix,
                        ':total_arquivos' => $totalImagens
                    ]);
                    break;

                case 'error':
                    $errorMessage = $data['errorMessage'] ?? 'Erro na normalização';
                    $stmt = $pdo->prepare("
                        UPDATE lotes_correcao
                        SET status = 'error_normalization',
                        mensagem_erro = :msg,
                        atualizado_em = NOW()
                        WHERE nome = :nome
                        ");
                    $stmt->execute([
                        ':nome' => $nomeLote,
                        ':msg' => $errorMessage,
                    ]);
                    break;

                default:
                    http_response_code(400);
                    echo 'event inválido';
                    return;
            }

            http_response_code(200);
            echo 'OK';
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Erro ao atualizar lote: ' . $e->getMessage();
        }
    }

    /**
     * ✅ CORRIGIDO: JOIN com l.id (não l.nome)
     */
    public function actionVerFila(): void
    {
        $db = $this->getDb();

        $stmt = $db->query("
            SELECT l.id, l.nome, l.descricao, l.s3_prefix, l.total_arquivos, l.status,
                   l.mensagem_erro, l.criado_em, l.atualizado_em, l.tempo_processamento_segundos,
                   r.corrigidas, r.defeituosas, r.repetidas, r.total AS total_paginas
            FROM lotes_correcao l
            LEFT JOIN resumo_lote r ON r.lote_id = l.id  -- ✅ l.id (bigint)
            ORDER BY l.criado_em DESC
        ");

        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->submenuAtivo = 'folhasResps';
        $this->pgTitulo = 'Fila de Análise :: Análise Automática';
        $this->pgSubtitulo = 'Permite acompanhar o processamento dos lotes';

        $this->render('verFila', compact('lotes'));
    }

    /**
     * ✅ CORRIGIDO: Busca por nome, depois converte para ID
     */
    public function actionDetalhar(): void
    {
        $nomeLote = $_GET['nome'] ?? null;  // ✅ nome ao invés de lote_id
        if (!$nomeLote) {
            $this->redirecionaComFlash('negative', 'Lote inválido');
            return;
        }

        $db = $this->getDb();

        // 1. Buscar lote por NOME
        $stmt = $db->prepare("
            SELECT id, nome, descricao, s3_prefix, total_arquivos, status,
                   mensagem_erro, criado_em, atualizado_em, tempo_processamento_segundos
            FROM lotes_correcao
            WHERE nome = :nomeLote
        ");
        $stmt->execute([':nomeLote' => $nomeLote]);
        $pacote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pacote) {
            $this->redirecionaComFlash('negative', 'Lote não encontrado');
            return;
        }

        $loteIdNumerico = $pacote['id'];  // ✅ ID para tabelas filhas
        $tempoSegundos = $pacote['tempo_processamento_segundos'];

        // 2. Buscar resumo (usa ID numérico)
        $stmt = $db->prepare("
            SELECT corrigidas, defeituosas, repetidas, total
            FROM resumo_lote
            WHERE lote_id = :loteId
        ");
        $stmt->execute([':loteId' => $loteIdNumerico]);
        $resumo = $stmt->fetch(PDO::FETCH_ASSOC);

        // 3. Folhas com problema (usa ID numérico)
        $stmt = $db->prepare("
            SELECT mensagem, tipo_folha, pagina, atualizado_em, status, caminho_folha, caderno_hash
            FROM paginas_lote
            WHERE lote_id = :loteId AND status IN ('defeituosa', 'repetida')
            ORDER BY pagina ASC
        ");
        $stmt->execute([':loteId' => $loteIdNumerico]);
        $folhasProblema = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Todas as páginas
        $stmt = $db->prepare("
            SELECT mensagem, lote_id, caderno_hash, tipo_folha, pagina, atualizado_em
            FROM paginas_lote
            WHERE lote_id = :loteId
            ORDER BY pagina ASC
        ");
        $stmt->execute([':loteId' => $loteIdNumerico]);
        $paginas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Cadernos incompletos
        $stmt = $db->prepare("
            SELECT DISTINCT 
                c.hash_caderno, 
                c.numero_paginas_processadas AS total_paginas, 
                c.status
            FROM cadernos_lote c
            INNER JOIN paginas_lote p ON p.caderno_hash = c.hash_caderno
            WHERE p.lote_id = :loteId 
              AND c.status = 'incompleto'
        ");
        $stmt->execute([':loteId' => $loteIdNumerico]);
        $cadernosIncompletos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pgTitulo = 'Lote ' . $pacote['nome'] . ' :: Detalhes';
        $this->render('detalhar', compact('pacote', 'resumo', 'folhasProblema', 'paginas', 'cadernosIncompletos', 'tempoSegundos'));

    }

    public function actionMeusEnvios(): void
    {
        $this->submenuAtivo = 'meusEnvios';
        $this->pgTitulo = 'Meus envios';

        $db = $this->getDb();

        $stmt = $db->query("
            SELECT id, nome, status, criado_em, atualizado_em, mensagem_erro, tempo_processamento_segundos
            FROM lotes_correcao
            ORDER BY criado_em DESC
        ");

        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->render('meusEnvios', compact('lotes'));
    }

    /**
     * ✅ CORRIGIDO: Recebe nome, converte para ID
     */
    public function actionMeusCadernos(): void
    {
        $nomeLote = $_GET['lote_nome'] ?? null;  // ✅ nome
        if (!$nomeLote) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['meusEnvios']);
            return;
        }

        $db = $this->getDb();

        // Converter nome → ID
        $stmt = $db->prepare("SELECT id FROM lotes_correcao WHERE nome = :nomeLote");
        $stmt->execute([':nomeLote' => $nomeLote]);
        $loteData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$loteData) {
            $this->redirecionaComFlash('negative', 'Lote não encontrado', ['meusEnvios']);
            return;
        }
        $loteIdNumerico = $loteData['id'];

        $stmt = $db->prepare("
            SELECT caderno_hash, COUNT(*) as total_folhas,
                   MIN(pagina) as primeira_pagina, MAX(pagina) as ultima_pagina
            FROM paginas_lote
            WHERE lote_id = :loteId AND caderno_hash IS NOT NULL AND caderno_hash != ''
            GROUP BY caderno_hash
            ORDER BY primeira_pagina ASC
        ");
        $stmt->execute([':loteId' => $loteIdNumerico]);
        $cadernos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pgTitulo = 'Cadernos do lote ' . $nomeLote;
        $this->render('meusCadernos', compact('nomeLote', 'cadernos'));
    }

    /**
     * ✅ AUTOMATIZADA: Recebe loteId, envia para Java com callbackUrl
     */
    public function actionColetar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo "Método não permitido.";
            exit;
        }

        $loteIdNumerico = (int)($_POST['loteId'] ?? 0);
        $isTriggerCall = $_POST['trigger'] ?? false;  // flag para calls automáticos

        if (!$loteIdNumerico) {
            http_response_code(400);
            echo "loteId obrigatório.";
            return;
        }

        $db = $this->getDb();

        // Buscar lote por ID
        $stmt = $db->prepare("
        SELECT id, nome, s3_prefix, total_arquivos, status
        FROM lotes_correcao 
        WHERE id = :id
    ");
        $stmt->execute([':id' => $loteIdNumerico]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote || $lote['status'] !== 'normalized') {
            http_response_code(400);
            echo "Lote não encontrado ou não está normalized.";
            return;
        }

        $bucket = 'dadoscorretor';
        $s3Prefix = rtrim($lote['s3_prefix'], '/');
        $inputPath = "s3://{$bucket}/cliente/saeb/uploads/normalizadas/{$s3Prefix}";

        // Aqui será o endpoint do qstione
        $callbackUrl = "http://" . $_SERVER['HTTP_HOST'] .
            "/uploadFolhasRespostas/actionCallbackNormalizacao";

        $payload = [
            'inputPath' => $inputPath,
            'nomeLote' => $lote['nome'],
            'loteIdNumerico' => (int)$lote['id'],     // ✅ ID numérico
            'callbackUrl' => $callbackUrl,             // ✅ PHP recebe callbacks
            'batchSize' => 100,
            'numThreads' => 8
        ];

        try {
            RabbitMQHelper::enviarParaFila('respostas_queue', $payload);

            // ✅ Atualiza status IMEDIATAMENTE para "fila"
            $stmt = $db->prepare("
            UPDATE lotes_correcao 
            SET status = 'fila', atualizado_em = NOW()
            WHERE id = :id
        ");
            $stmt->execute([':id' => $loteIdNumerico]);

            echo "Lote {$lote['nome']} enviado para processamento.";

        } catch (Throwable $e) {
            // ✅ Erro → volta para normalized
            $stmt = $db->prepare("
            UPDATE lotes_correcao 
            SET status = 'normalized', mensagem_erro = :erro
            WHERE id = :id
        ");
            $stmt->execute([
                ':id' => $loteIdNumerico,
                ':erro' => 'Falha RabbitMQ: ' . $e->getMessage()
            ]);

            http_response_code(500);
            echo "Falha RabbitMQ: " . $e->getMessage();
        }
    }


    /**
     * ✅ CORRIGIDO: actionRecalcular (igual actionColetar)
     */
    public function actionRecalcular(): void
    {
        $nomeLote = $_POST['lote_nome'] ?? null;
        if (!$nomeLote) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['verFila']);
            return;
        }

        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT id, nome, s3_prefix, total_arquivos, status
            FROM lotes_correcao
            WHERE nome = :nomeLote
        ");
        $stmt->execute([':nomeLote' => $nomeLote]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            $this->redirecionaComFlash('negative', 'Lote não encontrado', ['verFila']);
            return;
        }

        try {
            $db->beginTransaction();

            $loteIdNumerico = $lote['id'];

            $folhasProblemaStmt = $db->prepare("
                SELECT id, caderno_hash, pagina, status
                FROM paginas_lote
                WHERE lote_id = :loteId AND status IN ('defeituosa', 'repetida')
            ");
            $folhasProblemaStmt->execute([':loteId' => $loteIdNumerico]);
            $folhasProblema = $folhasProblemaStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($folhasProblema as $folha) {
                $updateStmt = $db->prepare("
                    UPDATE paginas_lote
                    SET status = 'pendente_recorte', atualizado_em = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([':id' => $folha['id']]);
            }

            $db->commit();

            $bucket = 'dadoscorretor';
            $s3Prefix = rtrim($lote['s3_prefix'], '/');
            $inputPath = "s3://{$bucket}/{$s3Prefix}";

            $payload = [
                'inputPath' => $inputPath,
                'batchSize' => 10,
                'numThreads' => 2,
                'loteId' => $nomeLote,  // ✅ Nome para Java
            ];

            RabbitMQHelper::enviarParaFila('respostas_queue', $payload);

            $stmt = $db->prepare("
                UPDATE lotes_correcao
                SET status = 'em_processamento', atualizado_em = :agora
                WHERE nome = :nomeLote
            ");
            $stmt->execute([
                ':agora' => date('Y-m-d H:i:s'),
                ':nomeLote' => $nomeLote,
            ]);

            $this->redirecionaComFlash('success', 'Lote reenviado para recálculo', ['verFila']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->redirecionaComFlash('negative', 'Erro ao recalcular: ' . $e->getMessage(), ['verFila']);
        }
    }

    private function render(string $view, array $data = []): void
    {
        extract($data);
        $controller = $this;
        require __DIR__ . "/../views/uploadFolhasRespostas/{$view}.php";
    }

    private function redirecionaComFlash(string $tipo, string $msg, array $action = []): void
    {
        $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg];
        $actionStr = $action ? $action[0] : 'verFila';
        header("Location: index.php?action={$actionStr}");
        exit;
    }

    private function redirect(string $action): void
    {
        header("Location: index.php?action={$action}");
        exit;
    }

    public function actionCallbackProcessamento(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Método não permitido';
            return;
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['nomeLote'])) {
            http_response_code(400);
            echo 'Payload inválido';
            return;
        }

        $nomeLote = $data['nomeLote'];
        $paginas  = $data['paginas']            ?? [];
        $cCompletos   = $data['cadernosCompletos']   ?? [];
        $cIncompletos = $data['cadernosIncompletos'] ?? [];

        $db = $this->getDb();

        // Resolver lote_id
        $stmt = $db->prepare("SELECT id FROM lotes_correcao WHERE nome = :nome");
        $stmt->execute([':nome' => $nomeLote]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(400);
            echo 'Lote não encontrado';
            return;
        }
        $loteId = (int)$row['id'];

        try {
            $db->beginTransaction();

            // 1) Páginas -> paginas_lote
            if (!empty($paginas)) {
                $sqlPagina = "
                INSERT INTO paginas_lote
                    (caderno_hash, pagina, lote_id, tipo_folha, status, mensagem, caminho_folha, criado_em, atualizado_em)
                VALUES
                    (:hashCaderno, :pagina, :loteId, :tipoFolha, :status, :mensagem, :caminhoFolha, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    lote_id       = VALUES(lote_id),
                    tipo_folha    = VALUES(tipo_folha),
                    status        = VALUES(status),
                    mensagem      = VALUES(mensagem),
                    caminho_folha = VALUES(caminho_folha),
                    atualizado_em = NOW()
            ";
                $stmtPag = $db->prepare($sqlPagina);

                foreach ($paginas as $p) {
                    $stmtPag->execute([
                        ':hashCaderno' => $p['hashCaderno'] ?? null,
                        ':pagina'      => (int)($p['pagina'] ?? 0),
                        ':loteId'      => $loteId,
                        ':tipoFolha'   => $p['tipoFolha'] ?? 'Resposta',
                        ':status'      => $p['status'] ?? 'defeituosa',
                        ':mensagem'    => $p['mensagem'] ?? null,
                        ':caminhoFolha'=> $p['caminhoFolha'] ?? null,
                    ]);
                }
            }

            // 2) Cadernos completos/incompletos -> cadernos_lote
            $sqlCaderno = "
            INSERT INTO cadernos_lote (hash_caderno, qr_texto_completo, numero_paginas_processadas, status)
            VALUES (:hash, :qrTexto, :numPags, :status)
            ON DUPLICATE KEY UPDATE
                qr_texto_completo        = VALUES(qr_texto_completo),
                numero_paginas_processadas = VALUES(numero_paginas_processadas),
                status                   = VALUES(status),
                updated_at               = CURRENT_TIMESTAMP
        ";
            $stmtCad = $db->prepare($sqlCaderno);

            foreach ($cCompletos as $c) {
                $stmtCad->execute([
                    ':hash'    => $c['hashCaderno'],
                    ':qrTexto' => $c['qrTextoCompleto'] ?? null,
                    ':numPags' => (int)($c['numeroPaginasProcessadas'] ?? 0),
                    ':status'  => $c['status'] ?? 'completo',
                ]);

                if (!empty($c['respostas']) && is_array($c['respostas'])) {
                    RespostasCadernoHelper::salvar($db, $c['hashCaderno'], $c['respostas']);
                }
            }

            foreach ($cIncompletos as $c) {
                $stmtCad->execute([
                    ':hash'    => $c['hashCaderno'],
                    ':qrTexto' => null,
                    ':numPags' => (int)($c['numeroPaginasProcessadas'] ?? 0),
                    ':status'  => $c['status'] ?? 'incompleto',
                ]);
            }

            // 4) Atualizar resumo_lote (equivalente ao LoteRepository.atualizarResumoLote)
            $sqlResumo = "
            INSERT INTO resumo_lote (lote_id, corrigidas, defeituosas, repetidas, total, atualizado_em)
            SELECT lote_id,
                   SUM(CASE WHEN status='corrigida'  THEN 1 ELSE 0 END),
                   SUM(CASE WHEN status='defeituosa' THEN 1 ELSE 0 END),
                   SUM(CASE WHEN status='repetida'   THEN 1 ELSE 0 END),
                   COUNT(*),
                   NOW()
            FROM paginas_lote
            WHERE lote_id = :loteId
            GROUP BY lote_id
            ON DUPLICATE KEY UPDATE
                corrigidas   = VALUES(corrigidas),
                defeituosas  = VALUES(defeituosas),
                repetidas    = VALUES(repetidas),
                total        = VALUES(total),
                atualizado_em= VALUES(atualizado_em)
        ";
            $stmtResumo = $db->prepare($sqlResumo);
            $stmtResumo->execute([':loteId' => $loteId]);

            $db->commit();
            http_response_code(200);
            echo 'OK';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo 'Erro ao processar callback: ' . $e->getMessage();
        }
    }

}
