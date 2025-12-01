<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $lotes array */
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
            <a class="blue active item" href="index.php?action=verFila">Análise INSE</a>
            <div class="right menu">
                <div class="ui dropdown item" style="text-align:center">
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

    <!-- Conteúdo principal -->
    <main id="main" style="flex-grow:1">
        <h1 class="ui dividing header">
            <div class="content">
                <?= htmlspecialchars($controller->pgTitulo) ?>
                <div class="sub header">Listagem dos lotes enviados para análise</div>
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
                        <i class="folder open icon"></i>
                        <div class="content">Lotes enviados</div>
                    </h3>

                    <table class="ui celled table">
                        <thead>
                        <tr>
                            <th>Lote</th>
                            <th>Status</th>
                            <th>Criado em</th>
                            <th>Atualizado em</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($lotes): ?>
                            <?php foreach ($lotes as $lote): ?>
                                <tr>
                                    <td><?= htmlspecialchars($lote['lote_id']) ?></td>
                                    <td><?= htmlspecialchars($lote['status']) ?></td>
                                    <td><?= date('d/m/Y H:i:s', strtotime($lote['criado_em'])) ?></td>
                                    <td>
                                        <?= $lote['atualizado_em'] ? date('d/m/Y H:i:s', strtotime($lote['atualizado_em'])) : '-' ?>
                                    </td>
                                    <td>
                                        <a class="ui mini button" href="index.php?action=meusCadernos&lote_id=<?= urlencode($lote['lote_id']) ?>">
                                            Cadernos
                                        </a>
                                        <a class="ui mini basic button" href="index.php?action=downloadLote&lote_id=<?= urlencode($lote['lote_id']) ?>">
                                            Baixar lote inteiro
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">Nenhum lote enviado ainda.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
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

