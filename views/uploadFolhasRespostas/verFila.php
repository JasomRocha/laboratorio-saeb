<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $pacotes array */
/* @var $totalPacotes int */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($controller->pgTitulo) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
    <style>
        body { background-color: #f9f9f9; }
        #main { margin-top: 72px; padding: 2rem; }
        .muted { color: #999; font-size: 0.9em; }
    </style>
</head>
<body>
<div style="min-height: 100vh; display: flex; flex-direction: column">

    <!-- Menu Superior -->
    <header>
        <div class="ui top fixed small menu">
            <a class="logo header item" href="index.php">Laboratório</a>
            <a class="blue active item" href="index.php?action=verFila">Análise INSE</a>
            <div class="right menu">
                <div class="ui dropdown item" style="text-align:center">
                    Conectado como<br><strong>qstione</strong>
                    <i class="dropdown icon"></i>
                    <div class="menu">
                        <a class="item"><i class="user icon"></i> Seus dados</a>
                        <a class="item"><i class="key icon"></i> Mudar senha</a>
                        <div class="ui divider"></div>
                        <a class="item"><i class="power off icon"></i> Sair</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Conteúdo Principal -->
    <main id="main" style="flex-grow:1">
        <h1 class="ui dividing header">
            <div class="content">
                <?= htmlspecialchars($controller->pgTitulo) ?>
                <div class="sub header"><?= htmlspecialchars($controller->pgSubtitulo) ?></div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <div class="three wide column">
                <?php require __DIR__ . '/incl/menuLateral.php'; ?>
            </div>

            <!-- Área principal -->
            <div class="thirteen wide column">
                <?php if (isset($_SESSION['flash'])): ?>
                    <?php $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
                    <div class="ui <?= $flash['tipo'] ?> message">
                        <?= htmlspecialchars($flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <!-- Barra de ações -->
                <nav class="ui menu">
                    <div class="borderless item">
                        <strong><?= $totalPacotes ?> pacotes na listagem</strong>
                    </div>
                    <div class="right item">
                        <a class="ui primary right labeled icon basic button" href="upload.php">
                            <i class="upload icon"></i>Enviar novo pacote
                        </a>
                    </div>
                </nav>

                <!-- Tabela de Pacotes -->
                <table class="ui selectable small striped table">
                    <thead class="full-width">
                    <tr>
                        <th colspan="4">Pacotes de Análise</th>
                        <th class="right aligned">Opções</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pacotes as $l): ?>
                        <?php
                        $status = $l['status'];
                        $rowClass = '';
                        $iconClass = '';

                        switch ($status) {
                            case 'concluido':
                                $rowClass = 'positive';
                                $iconClass = 'check green icon circular inverted';
                                break;
                            case 'erro':
                                $rowClass = 'negative';
                                $iconClass = 'warning orange circular inverted icon';
                                break;
                            case 'em_processamento':
                                $rowClass = '';
                                $iconClass = 'sync blue icon circular inverted loading';
                                break;
                            default:
                                $rowClass = '';
                                $iconClass = 'clock grey icon circular inverted';
                                break;
                        }

                        $totalPaginas = $l['total_paginas'] ?? $l['total_arquivos'];
                        $corrigidas = $l['corrigidas'] ?? 0;
                        $defeituosas = $l['defeituosas'] ?? 0;
                        $repetidas = $l['repetidas'] ?? 0;
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="collapsing center aligned">
                                <i class="<?= $iconClass ?>"></i>
                            </td>
                            <td>
                                <?= htmlspecialchars($l['lote_id']) ?><br>
                                <span class="muted">
                                        Carregado em <?= date('d/m/Y H:i:s', strtotime($l['criado_em'])) ?>
                                    </span>
                            </td>
                            <td>
                                <?php if ($status === 'concluido'): ?>
                                    <i class="check icon"></i> Concluído em <?= date('d/m/Y H:i:s', strtotime($l['atualizado_em'])) ?><br>
                                    <a class="ui green small label"><?= $corrigidas ?> corrigidas</a>
                                    <?php if ($defeituosas > 0): ?>
                                        <a class="ui red small label"><?= $defeituosas ?> defeituosas</a>
                                    <?php endif; ?>
                                    <?php if ($repetidas > 0): ?>
                                        <a class="ui black small label"><?= $repetidas ?> repetidas</a>
                                    <?php endif; ?>
                                <?php elseif ($status === 'erro'): ?>
                                    <i class="warning icon"></i> Erro em <?= date('d/m/Y H:i:s', strtotime($l['atualizado_em'])) ?><br>
                                    <a class="ui red small label"><?= $totalPaginas ?> páginas com problema</a>
                                <?php elseif ($status === 'em_processamento'): ?>
                                    <i class="sync loading icon"></i>Processando...
                                <?php else: ?>
                                    <i class="clock icon"></i>Aguardando processamento
                                <?php endif; ?>
                            </td>
                            <td class="center aligned single line collapsing">
                                <span class="muted"><?= $totalPaginas ?> páginas no arquivo</span>
                            </td>
                            <td class="right aligned">
                                <div class="ui small buttons">
                                    <a class="ui compact icon button" href="detalhar.php?lote_id=<?= urlencode($l['lote_id']) ?>">
                                        <i class="list icon"></i> detalhar
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script>
    $('.ui.dropdown').dropdown();

    // Auto-refresh a cada 30s
    setTimeout(function() {
        location.reload();
    }, 30000);
</script>
</body>
</html>

