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
            'mysql:host=localhost;port=23306;dbname=saeb;charset=utf8mb4',
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
            $model->titulo = $_POST['FormPacoteCorrecao']['tituloPacote'] ?? null;
            $model->arquivo = $_FILES['FormPacoteCorrecao_arquivo'] ?? null;

            if ($model->validate()) {
                $tituloPacote = $model->titulo;
                $zipTmp = $model->arquivo['tmp_name'];
                $zipName = $model->arquivo['name'];

                // 1) Valida extensão ZIP
                $ext = strtolower(pathinfo($zipName, PATHINFO_EXTENSION));
                if ($ext !== 'zip') {
                    $this->redirecionaComFlash('negative', 'Envie um arquivo ZIP.', ['upload']);
                    return;
                }

                // 2) Sobe ZIP bruto para S3
                $chave_zip = "cliente/saeb/uploads/{$tituloPacote}/{$zipName}";
                $okS3 = S3Helper::uploadFile($zipTmp, $chave_zip);

                if (!$okS3) {
                    $this->redirecionaComFlash('negative', 'Falha S3.', ['upload']);
                    return;
                }

                // 3) ✅ REGISTRA LOTE E PEGA ID GERADO
                $pdo = $this->getDb();
                $agora = date('Y-m-d H:i:s');

                $stmt = $pdo->prepare("
                INSERT INTO saeb_pacotes (titulo, chave_s3, total_arquivos, estado, criado_por, criado_em)
                VALUES (:titulo, :chave_s3, :total_arquivos, 'carregado', :criado_por, :criado_em)
                ON DUPLICATE KEY UPDATE
                    chave_s3 = VALUES(chave_s3),
                    total_arquivos = VALUES(total_arquivos),
                    estado = 'carregado'
            ");

                $stmt->execute([
                    ':titulo' => $model->titulo,
                    ':chave_s3' => $chave_zip,
                    ':total_arquivos' => 1,
                    ':criado_em' => $agora,
                    ':criado_por' => 'Usuário'
                ]);

                // ✅ PEGA O ID DO LOTE (NOVO ou EXISTENTE)
                $idPacote = (int)$pdo->lastInsertId();

                // 4) ✅ ENVIA PARA NORMALIZER COM LOTE ID
                $callbackUrl = "http://" . $_SERVER['HTTP_HOST'] . "/index.php?action=callbackNormalizacao";

                $payload = [
                    'tituloPacote' => $model->titulo,
                    'chaveZip' => $chave_zip,
                    'bucket' => 'dadoscorretor',
                    'callbackUrl' => $callbackUrl,
                    'idPacote' => $idPacote
                ];

                RabbitMQHelper::enviarParaFila('normalizacao_queue', $payload);

                $this->redirecionaComFlash(
                    'success',
                    "Lote '{$model->titulo}' (ID: {$idPacote}) enviado para normalização.",
                    ['verFila']
                );
            }
        }

        $this->render('upload', compact('model', 'acceptMimeTypes', 'coletas'));
    }

    public function actionCallbackNormalizacao(): void
    {
        header('Content-Type: application/json');

        $logFile = __DIR__ . '/../storage/logs/callback.log';
        if (!is_dir(dirname($logFile))) {
            if (!mkdir($concurrentDirectory = dirname($logFile), 0777, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'query_string' => $_SERVER['QUERY_STRING'] ?? 'N/A',
            'body' => file_get_contents('php://input'),
            'get_params' => $_GET
        ];

        file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n---\n", FILE_APPEND);

        error_log("=== Callback recebido em " . date('Y-m-d H:i:s') . " ===");

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            error_log("Método não permitido");
            echo json_encode(['error' => 'Método não permitido']);
            exit;
        }

        $body = file_get_contents('php://input');
        error_log("Body recebido: " . $body); // ✅ Log do payload

        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tituloPacote']) || empty($data['event'])) {
            http_response_code(400);
            error_log("Payload inválido: " . print_r($data, true));
            echo json_encode(['error' => 'Payload inválido']);
            exit; // ✅ exit
        }

        $tituloPacote = $data['tituloPacote'];
        $idPacote = $data['idPacote'];
        $event = $data['event'];

        error_log("Processando evento: {$event} para lote: {$tituloPacote}");

        $pdo = $this->getDb();

        try {
            switch ($event) {

                case 'iniciado':
                    $stmt = $pdo->prepare("
                        UPDATE saeb_pacotes
                        SET estado = 'normalizado'
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $idPacote]);
                    $affected = $stmt->rowCount(); // ✅ Verifica linhas afetadas
                    error_log("Event 'started' - Linhas afetadas: {$affected}");
                    break;

                case 'finalizado':
                    $normalizedPrefix = $data['normalizedPrefix'] ?? null;
                    $totalImagens = $data['totalImagens'] ?? 0;
                    $idPacote = $data['idPacote'] ?? null;

                    if (!$normalizedPrefix) {
                        http_response_code(400);
                        error_log("normalizedPrefix ausente");
                        echo json_encode(['error' => 'normalizedPrefix obrigatório']);
                        exit;
                    }

                    if (!$idPacote) {
                        http_response_code(400);
                        error_log("loteIdNumerico ausente");
                        echo json_encode(['error' => 'loteIdNumerico obrigatório']);
                        exit;
                    }

                    $stmt = $pdo->prepare("
                        UPDATE saeb_pacotes
                        SET estado = 'normalizado',
                            chave_s3 = :chave_s3,
                            total_arquivos = :total_arquivos
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $idPacote,
                        ':chave_s3' => $normalizedPrefix,
                        ':total_arquivos' => $totalImagens
                    ]);
                    $affected = $stmt->rowCount();
                    error_log("Banco atualizado - Linhas: {$affected}");

                    $bucket = 'dadoscorretor';
                    $inputPath = "s3://{$bucket}/{$normalizedPrefix}";

                    $payloadJava = [
                        'chaveImagens' => $inputPath,
                        'tituloPacote' => $tituloPacote,
                        'idPacote' => (int)$idPacote,
                        'callbackUrl' => "http://" . $_SERVER['HTTP_HOST'] . "/index.php?action=callbackProcessamento",
                    ];

                    try {
                        RabbitMQHelper::enviarParaFila('respostas_queue', $payloadJava);

                        $stmtFila = $pdo->prepare("
                            UPDATE saeb_pacotes 
                            SET estado = 'em_processamento'
                            WHERE id = :id
                        ");
                        $stmtFila->execute([':id' => $idPacote]);

                        error_log("✅ Enviado para Java automaticamente - Lote ID: {$idPacote}");

                    } catch (Throwable $e) {
                        error_log("❌ Falha RabbitMQ para Java: " . $e->getMessage());
                    }
                    break;

                case 'error':
                    $errorMessage = $data['errorMessage'] ?? 'Erro na normalização';
                    $stmt = $pdo->prepare("
                    UPDATE saeb_pacotes
                    SET estado = 'erro_normalizacao',
                        mensagem_erro = :msg,
                        atualizado_em = NOW()
                    WHERE titulo = :nome
                    ");
                    $stmt->execute([
                        ':titulo' => $tituloPacote,
                        ':msg' => $errorMessage,
                    ]);
                    $affected = $stmt->rowCount();
                    error_log("Event 'error' - Linhas afetadas: {$affected}, Mensagem: {$errorMessage}");
                    break;

                default:
                    http_response_code(400);
                    error_log("Event inválido: {$event}");
                    echo json_encode(['error' => 'event inválido']);
                    exit;
            }

            http_response_code(200);
            echo json_encode(['estado' => 'OK', 'event' => $event, 'tituloPacote' => $tituloPacote]);
            error_log("Callback processado com sucesso");
            exit;

        } catch (Throwable $e) {
            error_log("ERRO no callback: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());

            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    public function actionVerFila(): void
    {
        $db = $this->getDb();

        $stmt = $db->query("
        SELECT 
            p.id,
            p.titulo,
            p.chave_s3,
            p.total_arquivos,
            p.estado,
            p.criado_por,
            p.criado_em,
            -- resumo a partir de imagens_processadas
            COALESCE(r.corrigidas, 0) AS corrigidas,
            COALESCE(r.defeituosas, 0) AS defeituosas,
            COALESCE(r.total, 0) AS total_paginas
        FROM saeb_pacotes p
        LEFT JOIN (
            SELECT 
                pacote_id,
                COUNT(CASE WHEN estado = 'Ok' THEN 1 END) AS corrigidas,
                COUNT(CASE WHEN estado = 'erro' THEN 1 END) AS defeituosas,
                COUNT(*) AS total
            FROM saeb_imagens_processadas
            GROUP BY pacote_id
        ) r ON r.pacote_id = p.id
        ORDER BY p.criado_em DESC
    ");

        $pacotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->submenuAtivo = 'folhasResps';
        $this->pgTitulo = 'Fila de Análise :: Análise Automática';
        $this->pgSubtitulo = 'Permite acompanhar o processamento dos pacotes';

        $this->render('verFila', ['pacotes' => $pacotes]);
    }

    public function actionDetalhar(): void
    {
        $nomePacote = $_GET['titulo'] ?? null;
        if (!$nomePacote) {
            $this->redirecionaComFlash('negative', 'Pacote inválido');
            return;
        }

        $db = $this->getDb();

        // 1. Buscar pacote por título (nome)
        $stmt = $db->prepare("
        SELECT id, titulo, chave_s3, total_arquivos, estado,
               criado_por, criado_em
        FROM pacotes
        WHERE titulo = :nomePacote
    ");
        $stmt->execute([':nomePacote' => $nomePacote]);
        $pacote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pacote) {
            $this->redirecionaComFlash('negative', 'Pacote não encontrado');
            return;
        }

        $pacoteId = $pacote['id'];

        // 2. Resumo de imagens processadas (Ok vs erro)
        $stmt = $db->prepare("
        SELECT 
            COUNT(CASE WHEN estado = 'Ok' THEN 1 END) AS corrigidas,
            COUNT(CASE WHEN estado = 'erro' THEN 1 END) AS defeituosas,
            COUNT(*) AS total
        FROM imagens_processadas
        WHERE pacote_id = :pacoteId
    ");
        $stmt->execute([':pacoteId' => $pacoteId]);
        $resumo = $stmt->fetch(PDO::FETCH_ASSOC);

        // 3. Imagens com problema (estado = erro)
        $stmt = $db->prepare("
        SELECT 
            ip.chave_s3,
            ip.mensagem,
            ip.estado,
            ip.criado_em,
            il.pagina,
            q.codigo_identificacao AS questionario_codigo
        FROM imagens_processadas ip
        LEFT JOIN imagens_lidas il ON il.chave_s3 = ip.chave_s3 AND il.pacote_id = ip.pacote_id
        LEFT JOIN questionarios q ON q.id = il.questionario_id
        WHERE ip.pacote_id = :pacoteId 
          AND ip.estado = 'erro'
        ORDER BY il.pagina ASC
    ");
        $stmt->execute([':pacoteId' => $pacoteId]);
        $imagensProblema = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Todas as imagens lidas do pacote
        $stmt = $db->prepare("
        SELECT 
            il.chave_s3,
            il.pagina,
            il.criado_em,
            q.codigo_identificacao AS questionario_codigo,
            ip.estado,
            ip.mensagem
        FROM imagens_lidas il
        LEFT JOIN questionarios q ON q.id = il.questionario_id
        LEFT JOIN imagens_processadas ip ON ip.chave_s3 = il.chave_s3 AND ip.pacote_id = il.pacote_id
        WHERE il.pacote_id = :pacoteId
        ORDER BY il.pagina ASC
    ");
        $stmt->execute([':pacoteId' => $pacoteId]);
        $imagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Questionários incompletos ou parcialmente lidos
        $stmt = $db->prepare("
        SELECT 
            q.codigo_identificacao,
            q.estado,
            COUNT(il.id) AS total_imagens
        FROM questionarios q
        INNER JOIN imagens_lidas il ON il.questionario_id = q.id
        WHERE il.pacote_id = :pacoteId
          AND q.estado IN ('lido_parcialmente', 'criado')
        GROUP BY q.id, q.codigo_identificacao, q.estado
    ");
        $stmt->execute([':pacoteId' => $pacoteId]);
        $questionariosIncompletos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Questionários completos (opcional, para estatísticas)
        $stmt = $db->prepare("
        SELECT COUNT(*) AS total_completos
        FROM questionarios q
        INNER JOIN imagens_lidas il ON il.questionario_id = q.id
        WHERE il.pacote_id = :pacoteId
          AND q.estado = 'lido_completamente'
    ");
        $stmt->execute([':pacoteId' => $pacoteId]);
        $totalCompletos = $stmt->fetchColumn();

        // 7. Tempo de processamento (se você mantiver essa info em algum campo ou calcular a partir de criado_em)
        // Como não temos mais tempo_processamento_segundos em pacotes, você pode:
        // - Adicionar esse campo em pacotes se quiser
        // - Ou calcular a diferença entre a primeira e última imagem processada
        $tempoSegundos = null; // ou calcule conforme sua necessidade

        $this->pgTitulo = 'Pacote ' . $pacote['titulo'] . ' :: Detalhes';
        $this->render('detalhar', compact(
            'pacote',
            'resumo',
            'imagensProblema',
            'imagens',
            'questionariosIncompletos',
            'totalCompletos',
            'tempoSegundos'
        ));
    }


    public function actionMeusEnvios(): void
    {
        $this->submenuAtivo = 'meusEnvios';
        $this->pgTitulo = 'Meus envios';

        $db = $this->getDb();

        $stmt = $db->query("
            SELECT id, titulo, estado, criado_em, total_arquivos
            FROM saeb_pacotes
            ORDER BY criado_em DESC
        ");

        $pacotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->render('meusEnvios', compact('pacotes'));
    }

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
        $this->render('meusCadernos', compact('nomeLote', 'cadernos', 'loteIdNumerico'));
    }

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
        $inputPath = "s3://{$bucket}/cliente/saeb/normalizadas/{$s3Prefix}";

        // Aqui será o endpoint do qstione
        $callbackUrl = "http://" . $_SERVER['HTTP_HOST'] .
            "/index.php?action=callbackNormalizacao";

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
        error_log("🎯 CALLBACK INICIADO - PID: " . getmypid());
        error_log("🎯 URI: " . $_SERVER['REQUEST_URI']);

        $body = file_get_contents('php://input');
        error_log("📦 Body recebido: " . substr($body, 0, 2000));

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            error_log("❌ Método não permitido");
            echo json_encode(['error' => 'Método não permitido']);
            exit;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            http_response_code(400);
            error_log("❌ Payload inválido: JSON malformado");
            echo json_encode(['error' => 'Payload inválido']);
            exit;
        }

        $event = $data['event'] ?? null;

        // ✅ Detecta formato: simples ou completo
        $isFormatoSimples = isset($data['idPacote']) && !isset($data['pacote']);

        if ($isFormatoSimples) {
            $pacoteId = (int)$data['idPacote'];
            error_log("📌 Formato SIMPLES detectado | pacoteId=$pacoteId | event=$event");

            $this->processarCallbackSimples($pacoteId, $event);
            exit;
        }

        // Formato completo
        $pacoteData              = $data['pacote'] ?? null;
        $questionarios           = $data['questionarios'] ?? [];
        $imagensLidas            = $data['imagensLidas'] ?? [];
        $imagensProcessadas      = $data['imagensProcessadas'] ?? [];
        $respostasLidas          = $data['respostasLidas'] ?? [];
        $questionariosIncompletos = $data['questionariosIncompletos'] ?? [];
        $tempoProcessamento      = $data['tempoProcessamento'] ?? null;
        $chunkIndex              = $data['chunkIndex'] ?? null;

        if (!$pacoteData || empty($pacoteData['idPacote'])) {
            http_response_code(400);
            error_log("❌ Payload completo sem pacote.idPacote");
            echo json_encode(['error' => 'Payload inválido - pacote.idPacote obrigatório']);
            exit;
        }

        $pacoteId = (int)$pacoteData['idPacote'];

        error_log(sprintf(
            "✅ CALLBACK COMPLETO | pacoteId=%d | Event=%s | Chunk=%s | Q=%d | ImgLidas=%d | ImgProc=%d | Resp=%d",
            $pacoteId,
            $event,
            var_export($chunkIndex, true),
            count($questionarios),
            count($imagensLidas),
            count($imagensProcessadas),
            count($respostasLidas)
        ));

        $pdo = $this->getDb();
        $pdo->beginTransaction();

        try {
            // 1) PACOTE
            error_log("📦 Salvando/atualizando pacote id=$pacoteId...");
            $sqlPacote = "
            INSERT INTO saeb_pacotes (id, titulo, chave_s3, total_arquivos, estado, criado_por, criado_em)
            VALUES (:id, :titulo, :chaveS3, :totalArquivos, :estado, :criadoPor, NOW())
            ON DUPLICATE KEY UPDATE
                estado = VALUES(estado),
                total_arquivos = COALESCE(VALUES(total_arquivos), total_arquivos)
        ";
            $stmtPacote = $pdo->prepare($sqlPacote);
            $stmtPacote->execute([
                ':id'            => $pacoteId,
                ':titulo'        => $pacoteData['titulo'] ?? ('Pacote ' . $pacoteId),
                ':chaveS3'       => $pacoteData['chaveS3'] ?? null,
                ':totalArquivos' => $pacoteData['totalArquivos'] ?? null,
                ':estado'        => $pacoteData['estado'] ?? 'em_processamento',
                ':criadoPor'     => $pacoteData['criadoPor'] ?? 'User',
            ]);
            error_log("✅ Pacote salvo/atualizado");

            // 3) IMAGENS LIDAS
            if (!empty($imagensLidas)) {
                error_log("🖼 Salvando " . count($imagensLidas) . " imagens lidas...");

                $sqlImgLida = "
                INSERT INTO saeb_imagens_lidas (chave_s3, pacote_id, pagina, questionario_id, criado_em)
                VALUES (
                    :chaveS3,
                    :pacoteId,
                    :pagina,
                    (SELECT id FROM saeb_questionarios WHERE codigo_identificacao = :questionarioCodigo LIMIT 1),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    pagina         = VALUES(pagina),
                    questionario_id = VALUES(questionario_id)
            ";
                $stmtImgLida = $pdo->prepare($sqlImgLida);

                // log e detecção de hashes inexistentes
                $hashesImgInexistentes = [];

                foreach ($imagensLidas as $img) {
                    $codigo = $img['questionarioId'] ?? null;

                    if (!$codigo) {
                        error_log("⚠️ Imagem sem questionarioId, pulando: " . json_encode($img));
                        continue;
                    }

                    // testa se existe questionário para esse hash
                    $stmtCheck = $pdo->prepare("SELECT id FROM saeb_questionarios WHERE codigo_identificacao = :codigo LIMIT 1");
                    $stmtCheck->execute([':codigo' => $codigo]);
                    $idQuest = $stmtCheck->fetchColumn();

                    if (!$idQuest) {
                        $hashesImgInexistentes[$codigo] = true;
                        error_log("⚠️ Nenhum saeb_questionarios para hash=$codigo; NÃO inserindo imagem_lida para essa linha");
                        continue; // evita tentar inserir questionario_id NULL
                    }

                    // se existe, insere normalmente
                    $stmtImgLida->execute([
                        ':chaveS3'            => $img['chaveS3'],
                        ':pacoteId'           => $pacoteId,
                        ':pagina'             => (int)($img['pagina'] ?? 0),
                        ':questionarioCodigo' => $codigo,
                    ]);
                }

                if (!empty($hashesImgInexistentes)) {
                    error_log("⚠️ Resumo: hashes de questionário em imagens_lidas que NÃO existem em saeb_questionarios: " . implode(', ', array_keys($hashesImgInexistentes)));
                }

                error_log("✅ Imagens lidas processadas (algumas podem ter sido puladas por hash inexistente)");
            }

            // 4) IMAGENS PROCESSADAS
            if (!empty($imagensProcessadas)) {
                error_log("🖼 Salvando " . count($imagensProcessadas) . " imagens processadas...");
                $sqlImgProc = "
                INSERT INTO saeb_imagens_processadas (chave_s3, pacote_id, job_id, estado, mensagem, criado_em)
                VALUES (:chaveS3, :pacoteId, :jobId, :estado, :mensagem, NOW())
                ON DUPLICATE KEY UPDATE
                    estado   = VALUES(estado),
                    mensagem = VALUES(mensagem)
            ";
                $stmtImgProc = $pdo->prepare($sqlImgProc);

                foreach ($imagensProcessadas as $img) {
                    $stmtImgProc->execute([
                        ':chaveS3'  => $img['chaveS3'],
                        ':pacoteId' => $pacoteId,
                        ':jobId'    => $img['jobId'] ?? null,
                        ':estado'   => $img['estado'] ?? 'Ok',
                        ':mensagem' => $img['mensagem'] ?? null,
                    ]);
                }
                error_log("✅ Imagens processadas salvas: " . count($imagensProcessadas));
            }

            // 5) RESPOSTAS LIDAS
            if (!empty($respostasLidas)) {
                error_log("📝 Salvando " . count($respostasLidas) . " respostas lidas...");

                $mapCodigoParaId = [];
                $sqlGetQuestId = "SELECT id FROM saeb_questionarios WHERE codigo_identificacao = :codigo LIMIT 1";
                $stmtGetQuestId = $pdo->prepare($sqlGetQuestId);

                $sqlRespLida = "
                INSERT INTO saeb_respostas_lidas (questionario_id, codigo_questao, codigo_resposta, criado_em)
                VALUES (:questionarioId, :codigoQuestao, :codigoResposta, NOW())
                ON DUPLICATE KEY UPDATE
                    codigo_resposta = VALUES(codigo_resposta)
            ";
                $stmtRespLida = $pdo->prepare($sqlRespLida);

                $hashesRespInexistentes = [];

                foreach ($respostasLidas as $resp) {
                    $codigoQuestionario = $resp['questionarioCodigo'] ?? null;
                    if (!$codigoQuestionario) {
                        error_log("⚠️ Resposta sem questionarioCodigo, ignorando: " . json_encode($resp));
                        continue;
                    }

                    if (!isset($mapCodigoParaId[$codigoQuestionario])) {
                        $stmtGetQuestId->execute([':codigo' => $codigoQuestionario]);
                        $idQuestionario = $stmtGetQuestId->fetchColumn();
                        if (!$idQuestionario) {
                            $hashesRespInexistentes[$codigoQuestionario] = true;
                            error_log("⚠️ Questionário não encontrado para codigo_identificacao=$codigoQuestionario, ignorando respostas");
                            continue;
                        }
                        $mapCodigoParaId[$codigoQuestionario] = (int)$idQuestionario;
                    }

                    $stmtRespLida->execute([
                        ':questionarioId' => $mapCodigoParaId[$codigoQuestionario],
                        ':codigoQuestao'  => $resp['codigoQuestao'],
                        ':codigoResposta' => $resp['codigoResposta'],
                    ]);
                }

                if (!empty($hashesRespInexistentes)) {
                    error_log("⚠️ Resumo: hashes em respostas_lidas que NÃO existem em saeb_questionarios: " . implode(', ', array_keys($hashesRespInexistentes)));
                }

                error_log("✅ Respostas lidas salvas (com possíveis respostas ignoradas por hash inexistente)");
            }

            $pdo->commit();
            error_log("✅ ✅ ✅ Transação concluída com sucesso!");

            if ($event === 'finished') {
                error_log("🏁 Evento finished para pacoteId=$pacoteId");
                $stmtFinal = $pdo->prepare("UPDATE saeb_pacotes SET estado = 'processado' WHERE id = :id");
                $stmtFinal->execute([':id' => $pacoteId]);
                error_log("✅ Pacote marcado como processado");
            }

            http_response_code(200);
            echo json_encode([
                'status'    => 'OK',
                'event'     => $event,
                'pacoteId'  => $pacoteId,
                'salvos'    => [
                    'questionarios'      => count($questionarios),
                    'imagensLidas'       => count($imagensLidas),
                    'imagensProcessadas' => count($imagensProcessadas),
                    'respostasLidas'     => count($respostasLidas),
                ]
            ]);
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("❌ ERRO FATAL no callbackProcessamento: " . $e->getMessage());
            error_log("❌ Tipo do erro: " . get_class($e));
            error_log("❌ Arquivo/Linha: " . $e->getFile() . ':' . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());

            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Processa callbacks simples (só idPacote + event)
     */
    private function processarCallbackSimples(int $pacoteId, ?string $event): void
    {
        $pdo = $this->getDb();

        try {
            if ($event === 'processing_started') {
                $stmt = $pdo->prepare("
                INSERT INTO saeb_pacotes (id, titulo, estado, criado_em)
                VALUES (:id, :titulo, 'em_processamento', NOW())
                ON DUPLICATE KEY UPDATE estado = 'em_processamento'
            ");
                $stmt->execute([
                    ':id' => $pacoteId,
                    ':titulo' => 'Pacote ' . $pacoteId
                ]);
                error_log("✅ Pacote $pacoteId → em_processamento");
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'OK',
                'event' => $event,
                'pacoteId' => $pacoteId
            ]);
            error_log("✅ Callback simples processado");

        } catch (\Throwable $e) {
            error_log("❌ Erro no callback simples: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }


}
