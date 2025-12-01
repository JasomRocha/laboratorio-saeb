<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $loteId string */
/* @var $cadernos array */
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

    <!-- Menu superior -->
    <header>
        <div class="ui top fixed large menu">
            <a class="logo header item" href="index.php">Laboratório</a>
            <a class="blue active item" href="verFila.php">Análise INSE</a>
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

    <!-- Conteúdo principal -->
    <main id="main" style="flex-grow:1">
        <h1 class="ui dividing header">
            <div class="content">
                Cadernos do lote <?= htmlspecialchars($loteId) ?>
                <div class="sub header">Baixe as folhas agrupadas por caderno</div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <div class="three wide column">
                <?php require __DIR__ . '/incl/menuLateral.php'; ?>
            </div>

            <!-- Conteúdo principal -->
            <div class="thirteen wide column">
                <div class="ui segment">
                    <h3 class="ui header">
                        <i class="book icon"></i>
                        <div class="content">Cadernos identificados</div>
                    </h3>

                    <table class="ui celled table">
                        <thead>
                        <tr>
                            <th>Caderno</th>
                            <th>Quantidade de folhas</th>
                            <th>Páginas</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($cadernos): ?>
                            <?php foreach ($cadernos as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['caderno_hash']) ?></td>
                                    <td><?= (int)$c['total_folhas'] ?></td>
                                    <td><?= (int)$c['primeira_pagina'] ?> - <?= (int)$c['ultima_pagina'] ?></td>
                                    <td>
                                        <a class="ui mini primary button" href="download_caderno.php?lote_id=<?= urlencode($loteId) ?>&caderno_hash=<?= urlencode($c['caderno_hash']) ?>">
                                            Baixar caderno (ZIP)
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">Nenhum caderno identificado para este lote.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <a class="ui button" href="index.php?action=meusEnvios">
                        <i class="angle left icon"></i> Voltar para Meus envios
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script>
    $('.ui.dropdown').dropdown();
</script>
</body>
</html>

