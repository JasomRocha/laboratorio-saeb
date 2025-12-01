<?php

class UploadFolhasRespostasController
{
    public string $pgTitulo = 'Upload de arquivos com folhas de respostas';
    public string $pgSubtitulo = 'Permite fazer o upload de arquivos com folhas de respostas para correção';
    public string $menuAtivo = 'corretor';
    public string $submenuAtivo = '';

    private PDO $db;

    public function __construct()
    {
        // Conexão PDO (ou usar classe DB estática)
        $this->db = new PDO(
            'mysql:host=localhost;port=23306;dbname=corretor_saeb;charset=utf8mb4',
            'root',
            'root',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
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
        $this->submenuAtivo = 'novaCorrecao';
        $model = new FormPacoteCorrecao();
        $acceptMimeTypes = FormPacoteCorrecao::$mimeTypes;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model->loteId = $_POST['FormPacoteCorrecao']['loteId'] ?? null;
            $model->arquivo = $_FILES['FormPacoteCorrecao_arquivo'] ?? null;

            if ($model->validate()) {
                // Determina tipo: ZIP ou PDF
                $ext = strtolower(pathinfo($model->arquivo['name'], PATHINFO_EXTENSION));

                if ($ext === 'zip') {
                    $this->_processaPacoteZip($model->arquivo['tmp_name']);
                } else {
                    $this->_processaPacotePdf($model);
                }
            }
        }

        $this->render('upload', compact('model', 'acceptMimeTypes'));
    }

    /**
     * Lista todos os pacotes (fila)
     */
    public function actionVerFila(): void
    {
        $this->submenuAtivo = 'folhasResps';

        $stmt = $this->db->query("
            SELECT l.id, l.lote_id, l.s3_prefix, l.total_arquivos, l.status,
                   l.mensagem_erro, l.criado_em, l.atualizado_em,
                   r.corrigidas, r.defeituosas, r.repetidas, r.total AS total_paginas
            FROM lotes_correcao l
            LEFT JOIN resumo_lote r ON r.lote_id = l.lote_id
            ORDER BY l.criado_em DESC
        ");

        $pacotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalPacotes = count($pacotes);

        $this->render('verFila', compact('pacotes', 'totalPacotes'));
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
            SELECT caderno_hash, numero_paginas_processadas AS total_paginas, status
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
    private function _processaPacoteZip(string $tempName): void
    {
        try {
            $bemSucedidos = [];
            $malSucedidos = [];

            // Usa helper similar ao zipWalker do Yii
            ZipHelper::walk(
                $tempName,
                function (string $entrada, string $caminho) use (&$bemSucedidos, &$malSucedidos) {
                    try {
                        $this->db->beginTransaction();
                        LoteCorrecao::adicionaPacote(basename($entrada, '.pdf'), $caminho, filesize($caminho));
                        $this->db->commit();
                        $bemSucedidos[] = $entrada;
                    } catch (Throwable $e) {
                        $this->db->rollBack();
                        $malSucedidos[] = sprintf('%s: %s', $entrada, $e->getMessage());
                    }
                },
                ['pdf']
            );

            if ($bemSucedidos) {
                CorretorServiceHelper::pingCorrecaoEnfileirada();
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
        require __DIR__ . "/../views/uploadFolhasRespostas/{$view}.php";
    }

    /**
     * Redireciona com flash message
     */
    private function redirecionaComFlash(string $tipo, string $msg, array $action = []): void
    {
        $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg];
        $url = $action ? implode('/', $action) : 'verFila';
        header("Location: {$url}.php");
        exit;
    }

    private function redirect(string $action): void
    {
        header("Location: {$action}.php");
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
        $loteId = $_POST['lote_id'] ?? null;
        if (!$loteId) {
            $this->redirecionaComFlash('negative', 'Lote inválido', ['verFila']);
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
            $this->redirecionaComFlash('negative', 'Lote não encontrado', ['verFila']);
            return;
        }

        if ($lote['total_arquivos'] <= 0) {
            $this->redirecionaComFlash('warning', 'Lote sem arquivos para processar', ['verFila']);
            return;
        }

        // Envia para RabbitMQ
        try {
            $bucket = 'dados-corretor';
            $s3Prefix = rtrim($lote['s3_prefix'], '/');
            $inputPath = "s3://{$bucket}/{$s3Prefix}";

            $payload = [
                'inputPath' => $inputPath,
                'batchSize' => 100,
                'numThreads' => 6,
                'loteId' => $loteId,
            ];

            RabbitMQHelper::enviarParaFila('respostas_queue', $payload);

            // Atualiza status
            $stmt = $db->prepare("
                UPDATE lotes_correcao
                SET status = 'em_processamento', atualizado_em = :agora
                WHERE lote_id = :lote_id
            ");
            $stmt->execute([
                ':agora' => date('Y-m-d H:i:s'),
                ':lote_id' => $loteId,
            ]);

            $this->redirecionaComFlash('success', 'Lote enviado para processamento', ['verFila']);
        } catch (Throwable $e) {
            $this->redirecionaComFlash('negative', 'Erro ao enviar para fila: ' . $e->getMessage(), ['verFila']);
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

        // Mesma lógica de coletar, mas com batchSize/threads menores
        $db = $this->getDb();
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
            $bucket = 'dados-corretor';
            $s3Prefix = rtrim($lote['s3_prefix'], '/');
            $inputPath = "s3://{$bucket}/{$s3Prefix}";

            $payload = [
                'inputPath' => $inputPath,
                'batchSize' => 10,
                'numThreads' => 2,
                'loteId' => $loteId,
            ];

            RabbitMQHelper::enviarParaFila('respostas_queue', $payload);

            $stmt = $db->prepare("
                UPDATE lotes_correcao
                SET status = 'em_processamento', atualizado_em = :agora
                WHERE lote_id = :lote_id
            ");
            $stmt->execute([
                ':agora' => date('Y-m-d H:i:s'),
                ':lote_id' => $loteId,
            ]);

            $this->redirecionaComFlash('success', 'Lote reenviado para recálculo', ['verFila']);
        } catch (Throwable $e) {
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

