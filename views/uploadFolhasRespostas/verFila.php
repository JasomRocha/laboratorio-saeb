<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $lotes array */

use controllers\UploadFolhasRespostasController;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
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
        <div class="ui top fixed large menu">
            <a class="logo header item" href="index.php">Laboratório</a>
            <a class="blue active item" href="index.php?action=verFila">Análise INSE</a>

            <div class="right menu">
                <div class="ui dropdown item" style="text-align: center">
                    Seu perfil
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
    <main id="main" style="flex-grow: 1">
        <h1 class="ui dividing header">
            <div class="content">
                <?= htmlspecialchars($controller->pgTitulo) ?>
                <div class="sub header"><?= htmlspecialchars($controller->pgSubtitulo) ?></div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <aside class="three wide column">
                <?php require __DIR__ . '/incl/menuLateral.php'; ?>
            </aside>

            <!-- Área principal -->
            <div class="thirteen wide column">
                <nav class="ui menu">
                    <div class="borderless item">
                        <strong>(<?= count($lotes) ?> pacotes na listagem)</strong>
                    </div>
                    <div class="right item">
                        <a class="ui primary right labeled icon basic button" href="index.php?action=upload">
                            <i class="upload icon"></i>Enviar novo pacote
                        </a>
                    </div>
                </nav>

                <table class="ui selectable small striped table">
                    <thead class="full-width">
                    <tr>
                        <th colspan="4">Pacotes de Análise</th>
                        <th class="right aligned">Opções</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lotes as $l): ?>
                        <?php
                        $status = $l['status'];
                        $rowClass = '';
                        $iconClass = '';
                        switch ($status) {
                            case 'concluido':
                            case 'finished': // novo
                                $rowClass  = 'positive';
                                $iconClass = 'check green icon circular inverted';
                                break;

                            case 'erro':
                            case 'error_normalization':
                            case 'error_processing':
                                $rowClass  = 'negative';
                                $iconClass = 'warning orange circular inverted icon';
                                break;

                            case 'em_processamento':
                                $rowClass  = '';
                                $iconClass = 'sync blue icon circular inverted loading';
                                break;

                            case 'uploaded_bruto':
                            case 'normalizing':
                            case 'normalized':
                            default:
                                $rowClass  = '';
                                $iconClass = 'clock grey icon circular inverted';
                                break;
                        }


                        $totalPaginas = $l['total_paginas'] !== null
                                ? (int)$l['total_paginas']
                                : (int)$l['total_arquivos'];

                        $corrigidas  = $l['corrigidas']  !== null ? (int)$l['corrigidas']  : 0;
                        $defeituosas = $l['defeituosas'] !== null ? (int)$l['defeituosas'] : 0;
                        $repetidas   = $l['repetidas']   !== null ? (int)$l['repetidas']   : 0;
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="collapsing center aligned">
                                <i class="<?= $iconClass ?>"></i>
                            </td>
                            <td>
                                <?= htmlspecialchars($l['nome']) ?><br>
                                <span class="muted">
                                    Carregado em <?= date('d/m/Y \à\s H:i:s', strtotime($l['criado_em'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($status === 'concluido' || $status === 'finished'): ?>
                                    <i class="check icon"></i>
                                    Concluído em <?= date('d/m/Y \à\s H:i:s', strtotime($l['atualizado_em'])) ?><br>
                                    <a class="ui green small label"><?= $corrigidas ?> corrigidas</a>
                                    <?php if ($defeituosas > 0): ?>
                                        <a class="ui red small label"><?= $defeituosas ?> defeituosas</a>
                                    <?php endif; ?>
                                    <?php if ($repetidas > 0): ?>
                                        <a class="ui black small label"><?= $repetidas ?> repetidas</a>
                                    <?php endif; ?>

                                <?php elseif (in_array($status, ['erro', 'error_normalization', 'error_processing'], true)): ?>
                                    <i class="warning icon"></i>
                                    Erro em <?= date('d/m/Y \à\s H:i:s', strtotime($l['atualizado_em'])) ?><br>
                                    <a class="ui red small label"><?= $totalPaginas ?> páginas com problema</a>

                                <?php elseif ($status === 'em_processamento'): ?>
                                    <i class="sync loading icon"></i>Processando...

                                <?php elseif ($status === 'uploaded_bruto'): ?>
                                    <i class="clock icon"></i>Aguardando normalização

                                <?php elseif ($status === 'normalizing'): ?>
                                    <i class="sync loading icon"></i>Normalizando imagens...

                                <?php elseif ($status === 'normalized'): ?>
                                    <i class="check icon"></i>Pronto para processamento

                                <?php else: ?>
                                    <i class="clock icon"></i>Aguardando processamento
                                <?php endif; ?>

                            </td>
                            <td class="center aligned single line collapsing">
                                <span class="muted"><?= $totalPaginas ?> páginas no arquivo</span>
                            </td>
                            <td class="right aligned">
                                <div class="ui small buttons">
                                    <?php if ($status === 'normalized' || $status === 'finished'): ?>
                                        <a class="ui compact icon button" href="index.php?action=detalhar&nome=<?= urlencode($l['nome']) ?>">
                                            <i class="list icon"></i> Detalhar
                                        </a>
                                        <a class="ui icon compact grey button" title="Baixar cópia do arquivo" href="index.php?action=downloadLote&nome=<?= urlencode($l['nome']) ?>">
                                            <i class="download icon"></i>
                                        </a>
                                    <?php endif; ?>
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
    setTimeout(function(){ location.reload(); }, 50000); // Auto refresh a cada 50s
</script>
</body>
</html>
