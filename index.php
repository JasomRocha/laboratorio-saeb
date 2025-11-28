<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de Folhas SAEB :: Área de Análise</title>

    <!-- Semantic UI CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">

    <style>
        body {
            background-color: #f9f9f9;
        }
        #main {
            margin-top: 72px;
            padding: 2rem;
        }
    </style>
</head>
<body>
<div style="min-height: 100vh; display: flex; flex-direction: column">

    <header>
        <div class="ui top fixed small menu">
            <a class="logo header item" href="index.php">Laboratório</a>
            <a class="blue active item" href="index.php">Análise INSE</a>

            <div class="right menu">
                <div class="ui dropdown item" style="text-align: center">
                    Conectado como<br>
                    <strong>Usuário Demo</strong>
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
                Envio de Questionários
                <div class="sub header">Permite fazer o upload de arquivos dos questionários</div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <div class="three wide column">
                <nav class="ui vertical fluid tabular menu">
                    <div class="header item">ANÁLISE INSE</div>
                    <a class="item" href="fila.php">
                        Fila de Análise                        <i class="horizontal ellipsis icon"></i>
                    </a>
                    <a class="item active" href="index.php">
                        Enviar para Análise                        <i class="upload icon"></i>
                    </a>
                </nav>
            </div>

            <!-- Área principal -->
            <div class="thirteen wide column">

                <!-- Formulário de Upload -->
                <div class="ui segment">
                    <h3 class="ui header">
                        <i class="upload icon"></i>
                        <div class="content">
                            Novo pacote de Análises                           <div class="sub header">Envie as imagens dos questionários preenchidos</div>
                        </div>
                    </h3>

                    <form action="upload.php" method="post" enctype="multipart/form-data" class="ui form">
                        <div class="field">
                            <label>Identificador do lote</label>
                            <input type="text" name="lote_id" placeholder="Ex: turma-7A-prova-01" required>
                        </div>

                        <div class="field">
                            <label>Selecione as imagens das folhas</label>
                            <input type="file" name="imagens[]" accept="image/*" multiple required>
                        </div>

                        <button type="submit" class="ui primary button">
                            <i class="upload icon"></i>
                            Enviar imagens
                        </button>
                    </form>
                </div>

                <!-- Mensagem informativa -->
                <div class="ui small basic message">
                    <strong>COMO FUNCIONA</strong><br>
                    <p>Após o envio, as imagens serão processadas automaticamente pelo sistema de leitura.</p>
                    <p>Você poderá acompanhar o status na <a href="fila.php">Fila de análise</a>.</p>
                </div>

            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer>
        <div class="ui fluid container">
            <div class="ui two columns grid middle aligned" style="padding: 1rem 0;">
                <div class="column left aligned">
                    Laboratório de Desenvolvido para processamento SAEB
                </div>
                <div class="column right aligned">
                    V2025.1.0 - Qstione
                </div>
            </div>
        </div>
    </footer>
</div>

<!-- Semantic UI JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script>
    // Ativar dropdowns
    $('.ui.dropdown').dropdown();
</script>
</body>
</html>

