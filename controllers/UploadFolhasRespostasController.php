<?php
require_once __DIR__ . '/../models/forms/FormPacoteCorrecao.php';
require_once __DIR__ . '/../models/LoteCorrecao.php';
require_once __DIR__ . '/../helpers/S3Helper.php';
require_once __DIR__ . '/../helpers/GhostscriptHelper.php';
require_once __DIR__ . '/../helpers/ZipHelper.php';
require_once __DIR__ . '/../helpers/RabbitMQHelper.php';
require_once __DIR__ . '/../helpers/uploadHelper.php';


class UploadFolhasRespostasController
{
    public string $pgTitulo = 'Upload de arquivos com folhas de respostas';
    public string $pgSubtitulo = 'Permite fazer o upload de arquivos com folhas de respostas para correção';
    public string $menuAtivo = 'corretor';
    public string $submenuAtivo = '';

    private PDO $db;

    public function __construct()
    {
        session_start();
        // Conexão PDO (ou usar classe DB estática)
        $this->db = new PDO(
            'mysql:host=localhost;port=23306;dbname=corretor_saeb;charset=utf8mb4',
            'root',
            'root',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Retorna a conexão PDO
     */
    private function getDb(): PDO
    {
        return $this->db;
    }
    /**
     * Redireciona para a primeira tela apropriada
     */
    public function actionIndex(): void
    {
        // Lógica de permissão (simplificado)
        $this->redirect('verFila');
    }

    /**
     * Exibe formulário de upload
     */
    public function actionUpload(): void
    {
        $model = new FormPacoteCorrecao();
        $acceptMimeTypes = FormPacoteCorrecao::$mimeTypes;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model->loteId = $_POST['FormPacoteCorrecao']['loteId'] ?? null;
            $model->arquivo = $_FILES['FormPacoteCorrecao_arquivo'] ?? null;

            if ($model->validate()) {
                $loteSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $model->loteId);
                $prefix = "localhost:8026/imgs/{$loteSafe}/";

                $totalImagens = uploadHelper::processarUpload(
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
                INSERT INTO lotes_correcao (lote_id, s3_prefix, total_arquivos, status, criado_em, atualizado_em)
                VALUES (:lote_id, :s3_prefix, :total, 'uploaded', :criado_em, :atualizado_em)
                ON DUPLICATE KEY UPDATE
                    s3_prefix = VALUES(s3_prefix),
                    total_arquivos = VALUES(total_arquivos),
                    status = 'uploaded',
                    atualizado_em = VALUES(atualizado_em)
            ");

                $stmt->execute([
                    ':lote_id'       => $model->loteId,
                    ':s3_prefix'     => $prefix,
                    ':total'         => $totalImagens,
                    ':criado_em'     => $agora,
                    ':atualizado_em' => $agora,
                ]);

                $this->redirecionaComFlash('success', "Upload realizado com sucesso ($totalImagens imagens)", ['verFila']);
            }
        }

        $this->render('upload', compact('model', 'acceptMimeTypes'));
    }



    /**
     * Lista todos os pacotes (fila)
     */
    public function actionVerFila(): void
    {
        $db = $this->getDb();

        $stmt = $db->query("
        SELECT l.id,
               l.lote_id,
               l.s3_prefix,
               l.total_arquivos,
               l.status,
               l.mensagem_erro,
               l.criado_em,
               l.atualizado_em,
               r.corrigidas,
               r.defeituosas,
               r.repetidas,
               r.total AS total_paginas
        FROM lotes_correcao l
        LEFT JOIN resumo_lote r ON r.lote_id = l.lote_id
        ORDER BY l.criado_em DESC
    ");

        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->submenuAtivo = 'folhasResps';
        $this->pgTitulo = 'Fila de Análise :: Análise Automática';
        $this->pgSubtitulo = 'Permite fazer o upload de arquivos de questionários';

        $this->render('verFila', compact('lotes'));
    }


    /**
     * Detalha um pacote específico
     */
    public function actionDetalhar(): void
    {
        $loteId = $_GET['lote_id'] ?? null;
        if (!$loteId) {
            $this->redirecionaComFlash('negative', 'Lote inválido');
        }

        $stmt = $this->db->prepare("
            SELECT id, lote_id, s3_prefix, total_arquivos, status,
                   mensagem_erro, criado_em, atualizado_em, tempo_processamento_segundos
            FROM lotes_correcao
            WHERE lote_id = :lote_id
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $pacote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pacote) {
            $this->redirecionaComFlash('negative', 'Pacote não encontrado');
        }

        // Buscar resumo
        $stmt = $this->db->prepare("
            SELECT corrigidas, defeituosas, repetidas, total
            FROM resumo_lote
            WHERE lote_id = :lote_id
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $resumo = $stmt->fetch(PDO::FETCH_ASSOC);

        // Folhas com problema
        $stmt = $this->db->prepare("
            SELECT mensagem, tipo_folha, pagina, atualizado_em, status, caminho_folha, caderno_hash
            FROM paginas_lote
            WHERE lote_id = :lote_id AND status IN ('defeituosa', 'repetida')
            ORDER BY pagina ASC
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $folhasProblema = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Logs gerais
        $stmt = $this->db->prepare("
            SELECT mensagem, tipo_folha, pagina, atualizado_em
            FROM paginas_lote
            WHERE lote_id = :lote_id
            ORDER BY pagina ASC
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $paginas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cadernos incompletos
        $stmt = $this->db->prepare("
            SELECT hash_caderno, numero_paginas_processadas AS total_paginas, status
            FROM cadernos_lote
            WHERE lote_id = :lote_id AND status = 'incompleto'
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $cadernosIncompletos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pgTitulo = 'Pacote #' . $pacote['id'] . ' :: Correção Automática';
        $this->render('detalhar', compact('pacote', 'resumo', 'folhasProblema', 'paginas', 'cadernosIncompletos'));
    }

    /**
     * Processa pacote ZIP (múltiplos PDFs)
     */
    private function _processaPacoteZip(string $tempName, string $loteId): void
    {
        try {
            $bemSucedidos = [];
            $malSucedidos = [];

            ZipHelper::processarZip(
                $tempName,
                function (string $entrada, string $caminho) use (&$bemSucedidos, &$malSucedidos) {
                    try {
                        $this->db->beginTransaction();
                        LoteCorrecao::adicionaPacote(basename($entrada, '.pdf'), $caminho, filesize($caminho), $entrada);
                        $this->db->commit();
                        $bemSucedidos[] = $entrada;
                    } catch (Throwable $e) {
                        $this->db->rollBack();
                        $malSucedidos[] = sprintf('%s: %s', $entrada, $e->getMessage());
                    }
                },
                ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp']
            );

            if ($bemSucedidos) {
                LoteCorrecao::marcarEnfileirado($loteId);
            }


            // Redireciona com mensagem apropriada
            if (!$malSucedidos) {
                $this->redirecionaComFlash('success', count($bemSucedidos) . ' pacotes processados com sucesso', ['verFila']);
            } elseif ($bemSucedidos) {
                $this->redirecionaComFlash('warning', 'Alguns pacotes falharam: ' . implode('; ', $malSucedidos), ['upload']);
            } else {
                throw new RuntimeException('Nenhuma entrada válida no ZIP');
            }
        } catch (Throwable $e) {
            $this->redirecionaComFlash('negative', $e->getMessage(), ['upload']);
        }
    }

    /**
     * Processa um PDF único
     */
    private function _processaPacotePdf(FormPacoteCorrecao $form): void
    {
        try {
            $this->db->beginTransaction();

            // Usa helper estático (ou classe model)
            LoteCorrecao::adicionaPacote(
                $form->loteId,
                $form->arquivo['tmp_name'],
                $form->arquivo['size']
            );

            $this->db->commit();

            CorretorServiceHelper::pingCorrecaoEnfileirada();

            $this->redirecionaComFlash('success', 'Arquivo carregado com sucesso', ['verFila']);
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->redirecionaComFlash('negative', 'Erro ao adicionar: ' . $e->getMessage(), ['upload']);
        }
    }

    /**
     * Renderiza uma view
     */
    private function render(string $view, array $data = []): void
    {
        extract($data);
        $controller = $this;
        require __DIR__ . "/../views/uploadFolhasRespostas/{$view}.php";
    }

    /**
     * Redireciona com flash message
     */
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


    /**
     * Lista os envios do usuário atual
     */
    public function actionMeusEnvios(): void
    {
        $this->submenuAtivo = 'meusEnvios';
        $this->pgTitulo = 'Meus envios';

        $db = $this->getDb();

        // Ajuste filtro por usuário se necessário (WHERE usuario_id = :id)
        $stmt = $db->query("
            SELECT lote_id, status, criado_em, atualizado_em, mensagem_erro
            FROM lotes_correcao
            ORDER BY criado_em DESC
        ");

        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->render('meusEnvios', compact('lotes'));
    }

    /**
     * Lista os cadernos de um lote específico
     */
    public function actionMeusCadernos(): void
    {
        $loteId = $_GET['lote_id'] ?? null;
        if (!$loteId) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['meusEnvios']);
            return;
        }

        $db = $this->getDb();

        // Agrupa folhas por caderno_hash
        $stmt = $db->prepare("
            SELECT 
                caderno_hash,
                COUNT(*) as total_folhas,
                MIN(pagina) as primeira_pagina,
                MAX(pagina) as ultima_pagina
            FROM paginas_lote
            WHERE lote_id = :lote_id AND caderno_hash IS NOT NULL AND caderno_hash != ''
            GROUP BY caderno_hash
            ORDER BY primeira_pagina ASC
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $cadernos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pgTitulo = 'Cadernos do lote';
        $this->render('meusCadernos', compact('loteId', 'cadernos'));
    }

    /**
     * Coleta respostas: envia lote para fila RabbitMQ
     */
    public function actionColetar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo "Método não permitido.";
            exit;
        }

        $loteId = $_POST['lote_id'] ?? null;

        if (!$loteId) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['fila']);
            return;
        }

        $db = $this->getDb();

        $stmt = $db->prepare("
        SELECT id, lote_id, s3_prefix, total_arquivos, status
        FROM lotes_correcao
        WHERE lote_id = :lote_id
    ");
        $stmt->execute([':lote_id' => $loteId]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            $this->redirecionaComFlash('negative', 'Lote não encontrado', ['fila']);
            return;
        }

        if ($lote['total_arquivos'] <= 0) {
            $this->redirecionaComFlash('warning', 'Lote sem arquivos para processar.', ['fila']);
            return;
        }

        $bucket = 'dadoscorretor';
        $s3Prefix = rtrim($lote['s3_prefix'], '/');
        $inputPath = "s3://{$bucket}/{$s3Prefix}";

        $payload = [
            'inputPath'  => $inputPath,
            'batchSize'  => 100,
            'numThreads' => 6,
            'loteId'     => $loteId,
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

            // Atualiza status para em_processamento
            $stmt = $db->prepare("
            UPDATE lotes_correcao
            SET status = 'em_processamento', atualizado_em = :agora
            WHERE lote_id = :lote_id
        ");
            $stmt->execute([
                ':agora'   => date('Y-m-d H:i:s'),
                ':lote_id' => $loteId,
            ]);

            $this->redirecionaComFlash('success', 'Lote enviado para processamento.', ['fila']);
        } catch (Throwable $e) {
            $this->redirecionaComFlash('negative', 'Falha ao enviar para RabbitMQ: ' . $e->getMessage(), ['fila']);
        }
    }


    /**
     * Recalcula um lote (reenvia para processamento)
     */
    public function actionRecalcular(): void
    {
        $loteId = $_POST['lote_id'] ?? null;
        if (!$loteId) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['verFila']);
            return;
        }

        $db = $this->getDb();

        // Busca lote
        $stmt = $db->prepare("
        SELECT id, lote_id, s3_prefix, total_arquivos, status
        FROM lotes_correcao
        WHERE lote_id = :lote_id
    ");
        $stmt->execute([':lote_id' => $loteId]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            $this->redirecionaComFlash('negative', 'Lote não encontrado', ['verFila']);
            return;
        }

        try {
            $db->beginTransaction();

            // Seleciona folhas com problema (defeituosa ou repetida) para tratamento / reprocessamento
            $folhasProblemaStmt = $db->prepare("
            SELECT id, caderno_hash, pagina, status
            FROM paginas_lote
            WHERE lote_id = :lote_id AND status IN ('defeituosa', 'repetida')
        ");
            $folhasProblemaStmt->execute([':lote_id' => $loteId]);
            $folhasProblema = $folhasProblemaStmt->fetchAll(PDO::FETCH_ASSOC);

            // Aqui fica a lógica específica para atualizar ou marcar essas folhas como para recálculo
            // Exemplo genérico: alterar status para 'pendente_recorte'
            foreach ($folhasProblema as $folha) {
                $updateStmt = $db->prepare("
                UPDATE paginas_lote
                SET status = 'pendente_recorte', atualizado_em = NOW()
                WHERE id = :id
            ");
                $updateStmt->execute([':id' => $folha['id']]);
            }

            $db->commit();

            // Prepara payload para fila RabbitMQ com batch e threads menores
            $bucket = 'dados-corretor';
            $s3Prefix = rtrim($lote['s3_prefix'], '/');
            $inputPath = "s3://{$bucket}/{$s3Prefix}";

            $payload = [
                'inputPath'  => $inputPath,
                'batchSize'  => 10,
                'numThreads' => 2,
                'loteId'     => $loteId,
                // Opcional: enviar IDs ou info das folhasProblema se consumidor suportar
            ];

            RabbitMQHelper::enviarParaFila('respostas_queue', $payload);

            // Atualiza status para 'em_processamento'
            $stmt = $db->prepare("
            UPDATE lotes_correcao
            SET status = 'em_processamento', atualizado_em = :agora
            WHERE lote_id = :lote_id
        ");
            $stmt->execute([
                ':agora'   => date('Y-m-d H:i:s'),
                ':lote_id' => $loteId,
            ]);

            $this->redirecionaComFlash('success', 'Lote reenviado para recálculo', ['verFila']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->redirecionaComFlash('negative', 'Erro ao recalcular: ' . $e->getMessage(), ['verFila']);
        }
    }


    /**
     * Download do lote inteiro (ZIP com todas as imagens)
     */
    public function actionDownload(): void
    {
        $loteId = $_GET['lote_id'] ?? null;
        if (!$loteId) {
            http_response_code(400);
            echo 'Lote inválido.';
            exit;
        }

        $db = $this->getDb();
        $stmt = $db->prepare("SELECT s3_prefix FROM lotes_correcao WHERE lote_id = :lote_id");
        $stmt->execute([':lote_id' => $loteId]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            http_response_code(404);
            echo 'Lote não encontrado.';
            exit;
        }

        S3Helper::downloadLoteZip($loteId, $lote['s3_prefix']);
    }

    /**
     * Download de um caderno específico (ZIP com folhas do caderno)
     */
    public function actionDownloadCaderno(): void
    {
        $loteId = $_GET['lote_id'] ?? null;
        $cadernoHash = $_GET['caderno_hash'] ?? null;

        if (!$loteId || !$cadernoHash) {
            http_response_code(400);
            echo 'Parâmetros inválidos.';
            exit;
        }

        $db = $this->getDb();

        // Busca folhas do caderno
        $stmt = $db->prepare("
            SELECT pagina, caminho_folha
            FROM paginas_lote
            WHERE lote_id = :lote_id AND caderno_hash = :caderno_hash
            ORDER BY pagina ASC
        ");
        $stmt->execute([':lote_id' => $loteId, ':caderno_hash' => $cadernoHash]);
        $folhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$folhas) {
            http_response_code(404);
            echo 'Nenhuma folha encontrada para este caderno.';
            exit;
        }

        S3Helper::downloadCadernoZip($loteId, $cadernoHash, $folhas);
    }
}

