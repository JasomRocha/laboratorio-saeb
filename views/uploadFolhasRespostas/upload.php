<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $model FormPacoteCorrecao */
/* @var $acceptMimeTypes string[] */

$maxUploadSize = ini_get('upload_max_filesize');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        <div class="ui top fixed small menu">
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
    <main id="main" style="flex-grow: 1">
        <h1 class="ui dividing header">
            <div class="content">
                <?= htmlspecialchars($controller->pgTitulo) ?>
                <div class="sub header"><?= htmlspecialchars($controller->pgSubtitulo) ?></div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <aside class="three wide column">
                <?php require __DIR__ . '/incl/menuLateral.php'; ?>
            </aside>

            <div class="thirteen wide column">
                <form action="upload.php" method="post" enctype="multipart/form-data" class="ui form">
                    <h4 class="ui top attached header">
                        Arquivo para upload
                    </h4>

                    <?php if ($model->hasErrors()): ?>
                        <div class="ui attached error icon message">
                            <i class="remove icon"></i>
                            <div class="content">
                                <?= $model->getErrorSummary() ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['flash'])): ?>
                        <?php $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
                        <div class="ui <?= $flash['tipo'] ?> message">
                            <?= htmlspecialchars($flash['msg']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="ui attached <?= $model->hasErrors() ? 'error' : '' ?> very padded segment">
                        <div class="<?= $model->hasErrors('arquivo') ? 'error' : '' ?> field">
                            <label for="FormPacoteCorrecao_arquivo">Arquivo</label>
                            <input type="file" name="FormPacoteCorrecao_arquivo" id="FormPacoteCorrecao_arquivo" accept="<?= implode(',', $acceptMimeTypes) ?>" required>
                            <div class="muted"><small>O arquivo não pode exceder <?= $maxUploadSize ?> de tamanho.</small></div>
                        </div>

                        <div class="<?= $model->hasErrors('loteId') ? 'error' : '' ?> field" id="descricaoWrapper">
                            <label for="FormPacoteCorrecao_loteId">Descrição</label>
                            <input type="text" name="FormPacoteCorrecao[loteId]" id="FormPacoteCorrecao_loteId" placeholder="Descrição do pacote" value="<?= htmlspecialchars($model->loteId ?? '') ?>">
                        </div>
                    </div>

                    <?php if (!$model->hasErrors()): ?>
                        <div class="ui warning small attached icon message">
                            <i class="file archive outline icon"></i>
                            <div class="content">
                                <div class="header">Tem muitos arquivos para enviar? Experimente o zip&hellip;</div>
                                <p>
                                    Você pode juntar todos os pacotes de correção em um arquivo zip e enviá-los de uma única vez para processamento.<br>
                                    Os arquivos zipados podem ter até <?= $maxUploadSize ?> de tamanho.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="ui bottom attached segment right aligned">
                        <button type="submit" class="ui primary button">Upload</button>
                        <a href="verFila.php" class="ui basic button left floated">
                            <i class="left double angle icon"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script>
    $('.ui.dropdown').dropdown();

    // Esconde campo descrição quando ZIP
    $('#FormPacoteCorrecao_arquivo').on('change', function() {
        var filename = $(this).val();
        var descWrapper = $('#descricaoWrapper');

        if (filename.match(/\.zip$/i)) {
            descWrapper.children('input').first().val('');
            descWrapper.addClass('hidden');
        } else {
            descWrapper.removeClass('hidden');
            try {
                filename = filename.split(/[\\\/]/).pop();
                descWrapper.children('input').first().val(filename.substring(0, filename.lastIndexOf('.')));
            } catch (e) {}
            descWrapper.children('input').first().focus();
        }
    });
</script>
</body>
</html>
