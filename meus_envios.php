<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $pdo = new PDO(
        'mysql:host=localhost:23306;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'root',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Ajuste aqui se tiver filtro por usuário (ex: WHERE usuario_id = :id)
    $stmt = $pdo->query("
        SELECT lote_id, status, criado_em, atualizado_em, mensagem_erro
        FROM lotes_correcao
        ORDER BY criado_em DESC
    ");
    $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    echo 'Erro ao acessar o banco: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Meus envios - SAEB</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
    <style>
        body { background-color: #f9f9f9; }
        main { margin-top: 72px; padding: 2rem; }
    </style>
</head>
<body>
<div style="min-height: 100vh; display: flex; flex-direction: column;">
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

    <main id="main" style="flex-grow: 1;">
        <h1 class="ui dividing header">
            <div class="content">
                Meus envios
                <div class="sub header">Listagem dos lotes enviados para análise</div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <div class="three wide column">
                <nav class="ui vertical fluid tabular menu">
                    <div class="header item">ANÁLISE INSE</div>
                    <a class="item" href="fila.php">
                        Fila de Análise
                        <i class="horizontal ellipsis icon"></i>
                    </a>
                    <a class="item" href="index.php">
                        Enviar para Análise
                        <i class="upload icon"></i>
                    </a>
                    <a class="item active" href="meus_envios.php">
                        Meus envios
                        <i class="folder open icon"></i>
                    </a>
                </nav>
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
                                    <td><?= $lote['atualizado_em'] ? date('d/m/Y H:i:s', strtotime($lote['atualizado_em'])) : '-' ?></td>
                                    <td>
                                        <a class="ui mini button" href="meus_cadernos.php?lote_id=<?= urlencode($lote['lote_id']) ?>">
                                            Cadernos
                                        </a>
                                        <a class="ui mini basic button" href="download.php?lote_id=<?= urlencode($lote['lote_id']) ?>">
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

    <!-- Footer (copie o mesmo do index.php) -->
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script>
    $('.ui.dropdown').dropdown();
</script>
</body>
</html>

