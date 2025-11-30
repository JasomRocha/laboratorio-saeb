<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$loteId = $_GET['lote_id'] ?? null;
if (!$loteId) {
    http_response_code(400);
    echo 'Lote inválido.';
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=localhost:23306;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'root',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Busca informações básicas do lote (reaproveitando ideia de detalhar.php)
    $stmtLote = $pdo->prepare("
        SELECT lote_id, s3_prefix, status, criado_em, atualizado_em
        FROM lotes_correcao
        WHERE lote_id = :lote_id
    ");
    $stmtLote->execute([':lote_id' => $loteId]);
    $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

    if (!$lote) {
        http_response_code(404);
        echo 'Lote não encontrado.';
        exit;
    }

    // AGRUPA cadernos dentro do lote
    // Ajuste 'caderno_id' e 'caminho_s3' para os nomes reais da sua tabela paginas_lote
    $stmtCad = $pdo->prepare("
        SELECT 
            caderno_hash,
            COUNT(*) AS total_folhas,
            MIN(pagina) AS primeira_pagina,
            MAX(pagina) AS ultima_pagina
        FROM paginas_lote
        WHERE lote_id = :lote_id
        GROUP BY caderno_hash
        ORDER BY caderno_hash
    ");
    $stmtCad->execute([':lote_id' => $loteId]);
    $cadernos = $stmtCad->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Cadernos do lote <?= htmlspecialchars($loteId) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
    <style>
        body { background-color: #f9f9f9; }
        main { margin-top: 72px; padding: 2rem; }
    </style>
</head>
<body>
<div style="min-height: 100vh; display: flex; flex-direction: column;">
    <!-- Copie o mesmo header dos outros arquivos -->

    <main id="main" style="flex-grow: 1;">
        <h1 class="ui dividing header">
            <div class="content">
                Cadernos do lote <?= htmlspecialchars($loteId) ?>
                <div class="sub header">Baixe as folhas agrupadas por caderno</div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <div class="three wide column">
                <nav class="ui vertical fluid tabular menu">
                    <div class="header item">ANÁLISE INSE</div>
                    <a class="item" href="fila.php">Fila de Análise<i class="horizontal ellipsis icon"></i></a>
                    <a class="item" href="index.php">Enviar para Análise<i class="upload icon"></i></a>
                    <a class="item active" href="meus_envios.php">Meus envios<i class="folder open icon"></i></a>
                </nav>
            </div>

            <!-- Conteúdo principal -->
            <div class="thirteen wide column">
                <div class="ui segment">
                    <a class="ui mini button" href="meus_envios.php">
                        <i class="angle left icon"></i> Voltar para Meus envios
                    </a>

                    <table class="ui celled table" style="margin-top: 1rem;">
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
                                        <a class="ui mini primary button"
                                           href="download_caderno.php?lote_id=<?= urlencode($loteId) ?>&caderno_hash=<?= urlencode($c['caderno_hash']) ?>">
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

                </div>
            </div>
        </div>
    </main>

    <!-- Footer igual aos outros -->
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script>
    $('.ui.dropdown').dropdown();
</script>
</body>
</html>

