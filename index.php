<?php

// Autoload do Composer (AWS SDK, RabbitMQ, etc.)
require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/models/forms/FormPacoteCorrecao.php';
require_once __DIR__ . '/models/LoteCorrecao.php';
require_once __DIR__ . '/helpers/S3Helper.php';
require_once __DIR__ . '/helpers/GhostscriptHelper.php';
require_once __DIR__ . '/helpers/ZipHelper.php';
require_once __DIR__ . '/helpers/RabbitMQHelper.php';
// Controller principal da funcionalidade
require_once __DIR__ . '/controllers/UploadFolhasRespostasController.php';

// Instancia o controller
$controller = new UploadFolhasRespostasController();

// action vem da query string, ex: index.php?action=verFila
$action = $_GET['action'] ?? 'index';

switch ($action) {
    // Tela inicial / decisão
    case 'index':
        $controller->actionIndex();
        break;

    // Upload de arquivos (formulário + POST)
    case 'upload':
        $controller->actionUpload();
        break;

    // Fila de análise (listagem de lotes)
    case 'verFila':
        $controller->actionVerFila();
        break;

    // Detalhamento de um lote específico
    case 'detalhar':
        $controller->actionDetalhar();
        break;

    // Meus envios (histórico do usuário)
    case 'meusEnvios':
        $controller->actionMeusEnvios();
        break;

    // Meus cadernos (por lote)
    case 'meusCadernos':
        $controller->actionMeusCadernos();
        break;

    // Enviar lote para processamento (coletar respostas)
    case 'coletar':
        $controller->actionColetar();
        break;

    // Recalcular um lote (reprocessar)
    case 'recalcular':
        $controller->actionRecalcular();
        break;

    // Download do lote inteiro (ZIP com todas as imagens)
    case 'download':
        $controller->actionDownload();
        break;

    // Download de um caderno específico (ZIP por caderno)
    case 'downloadCaderno':
        $controller->actionDownloadCaderno();
        break;

    // Qualquer outra coisa cai na tela inicial
    default:
        $controller->actionIndex();
        break;
}
