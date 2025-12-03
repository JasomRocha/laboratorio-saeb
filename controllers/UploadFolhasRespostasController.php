<?php
require_once __DIR__ . '/../models/forms/FormPacoteCorrecao.php';
require_once __DIR__ . '/../models/Coleta.php';
require_once __DIR__ . '/../helpers/S3Helper.php';
require_once __DIR__ . '/../helpers/GhostscriptHelper.php';
require_once __DIR__ . '/../helpers/ZipHelper.php';
require_once __DIR__ . '/../helpers/RabbitMQHelper.php';
require_once __DIR__ . '/../helpers/uploadHelper.php';

final class UploadFolhasRespostasController
{
    public string $pgTitulo = 'Upload de arquivos com folhas de respostas';
    public string $pgSubtitulo = 'Permite fazer o upload de arquivos com folhas de respostas para correção';
    public string $menuAtivo = 'corretor';
    public string $submenuAtivo = '';

    private PDO $db;

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
            $model->nomeLote = $_POST['FormPacoteCorrecao']['nomeLote'] ?? null;  // ✅ nomeLote
            $model->arquivo = $_FILES['FormPacoteCorrecao_arquivo'] ?? null;
            $model->descricao = $_POST['FormPacoteCorrecao']['descricao'] ?? null;

            if ($model->validate()) {
                $loteSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $model->nomeLote);
                $prefix = "localhost:8026/imgs/{$loteSafe}/";

                $totalImagens = UploadHelper::processarUpload(
                    $model->arquivo['tmp_name'],
                    $model->arquivo['name'],
                    $prefix
                );

                if ($totalImagens === 0) {
                    $this->redirecionaComFlash('negative', 'Nenhuma imagem processada/enviada.', ['upload']);
                    return;
                }

                $pdo = $this->getDb();
                $agora = date('Y-m-d H:i:s');

                $stmt = $pdo->prepare("
                    INSERT INTO lotes_correcao (nome, descricao, s3_prefix, total_arquivos, status, criado_em, atualizado_em)
                    VALUES (:nome, :descricao, :s3_prefix, :total, 'uploaded', :criado_em, :atualizado_em)
                    ON DUPLICATE KEY UPDATE
                        descricao = VALUES(descricao),
                        s3_prefix = VALUES(s3_prefix),
                        total_arquivos = VALUES(total_arquivos),
                        status = 'uploaded',
                        atualizado_em = VALUES(atualizado_em)
                ");

                $stmt->execute([
                    ':nome' => $model->nomeLote,          // ✅ nomeLote
                    ':descricao' => $model->descricao,
                    ':s3_prefix' => $prefix,
                    ':total' => $totalImagens,
                    ':criado_em' => $agora,
                    ':atualizado_em' => $agora,
                ]);

                $this->redirecionaComFlash('success', "Upload realizado com sucesso ($totalImagens imagens)", ['verFila']);
            }
        }

        $this->render('upload', compact('model', 'acceptMimeTypes', 'coletas'));
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
        $nomeLote = $_GET['lote_nome'] ?? null;  // ✅ nome ao invés de lote_id
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
            SELECT hash_caderno, numero_paginas_processadas AS total_paginas, status
            FROM cadernos_lote
            WHERE lote_id = :loteId AND status = 'incompleto'
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
     * ✅ CORRIGIDO: Recebe nome, converte para dados do lote
     */
    public function actionColetar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo "Método não permitido.";
            exit;
        }

        $nomeLote = $_POST['lote_nome'] ?? null;  // ✅ nomeLote
        if (!$nomeLote) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['verFila']);
            return;
        }

        $db = $this->getDb();

        // Buscar lote por NOME
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

        if ($lote['total_arquivos'] <= 0) {
            $this->redirecionaComFlash('warning', 'Lote sem arquivos para processar.', ['verFila']);
            return;
        }

        $bucket = 'dadoscorretor';
        $s3Prefix = rtrim($lote['s3_prefix'], '/');
        $inputPath = "s3://{$bucket}/{$s3Prefix}";

        $payload = [
            'inputPath'  => $inputPath,
            'batchSize'  => 100,
            'numThreads' => 6,
            'loteId'     => $nomeLote,  // ✅ Nome para Java
        ];
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
            $channel = $connection->channel();
            $queueName = 'respostas_queue';

            $channel->queue_declare($queueName, false, true, false, false);

            $msg = new \PhpAmqpLib\Message\AMQPMessage(
                $jsonPayload,
                [
                    'content_type'  => 'application/json',
                    'delivery_mode' => \PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_PERSISTENT,
                ]
            );

            $channel->basic_publish($msg, '', $queueName);
            $channel->close();
            $connection->close();

            // Atualiza status (usa NOME)
            $stmt = $db->prepare("
                UPDATE lotes_correcao
                SET status = 'em_processamento', atualizado_em = :agora
                WHERE nome = :nomeLote
            ");
            $stmt->execute([
                ':agora' => date('Y-m-d H:i:s'),
                ':nomeLote' => $nomeLote,
            ]);

            $this->redirecionaComFlash('success', 'Lote enviado para processamento.', ['verFila']);
        } catch (Throwable $e) {
            $this->redirecionaComFlash('negative', 'Falha ao enviar para RabbitMQ: ' . $e->getMessage(), ['verFila']);
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
                'inputPath'  => $inputPath,
                'batchSize'  => 10,
                'numThreads' => 2,
                'loteId'     => $nomeLote,  // ✅ Nome para Java
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
}
