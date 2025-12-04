<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $lotes array */

use controllers\UploadFolhasRespostasController;

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
                            <th>Nome do Lote</th>
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
                                    <td><?= htmlspecialchars($lote['nome']) ?></td>  <!-- ✅ nome -->
                                    <td>
                                        <?php
                                        if ($lote['status'] === 'concluido') {
                                            $statusClass = 'green';
                                        } elseif ($lote['status'] === 'em_processamento') {
                                            $statusClass = 'blue';
                                        } elseif ($lote['status'] === 'erro') {
                                            $statusClass = 'red';
                                        } else {
                                            $statusClass = 'grey';
                                        }
                                        ?>
                                        <span class="ui <?= $statusClass ?> label">
                                            <?= htmlspecialchars($lote['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i:s', strtotime($lote['criado_em'])) ?></td>
                                    <td>
                                        <?= $lote['atualizado_em'] ? date('d/m/Y H:i:s', strtotime($lote['atualizado_em'])) : '-' ?>
                                    </td>
                                    <td>
                                        <a class="ui mini primary button" href="index.php?action=meusCadernos&lote_nome=<?= urlencode($lote['nome']) ?>">  <!-- ✅ lote_nome -->
                                            <i class="book icon"></i> Cadernos
                                        </a>
                                        <a class="ui mini basic button" href="index.php?action=downloadLote&lote_nome=<?= urlencode($lote['nome']) ?>">  <!-- ✅ lote_nome -->
                                            <i class="download icon"></i> Baixar lote
                                        </a>
                                        <?php if ($lote['status'] === 'concluido' || $lote['status'] === 'erro'): ?>
                                            <a class="ui mini icon button" href="index.php?action=detalhar&lote_nome=<?= urlencode($lote['nome']) ?>" title="Ver detalhes">  <!-- ✅ lote_nome -->
                                                <i class="list icon"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="center aligned">
                                    <div class="ui message">
                                        <i class="inbox icon"></i>
                                        Nenhum lote enviado ainda.
                                    </div>
                                </td>
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
