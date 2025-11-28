<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$loteId = $_GET['lote_id'] ?? null;
if (!$loteId) {
    http_response_code(400);
    echo "Lote inválido.";
    exit;
}

$pdo = new PDO(
        'mysql:host=localhost:23306;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'root',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Dados do lote
$stmt = $pdo->prepare("
    SELECT id, lote_id, s3_prefix, total_arquivos, status,
           mensagem_erro, criado_em, atualizado_em,
           tempo_processamento_segundos
    FROM lotes_correcao
    WHERE lote_id = :lote_id
");

$stmt->execute([':lote_id' => $loteId]);
$lote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lote) {
    echo "Lote não encontrado.";
    exit;
}

$tempoSegundos = null;
if (isset($lote['tempo_processamento_segundos']) && $lote['tempo_processamento_segundos'] !== null) {
    $tempoSegundos = (int)$lote['tempo_processamento_segundos'];
} elseif (!empty($lote['criado_em']) && !empty($lote['atualizado_em'])) {
    $inicio = strtotime($lote['criado_em']);
    $fim    = strtotime($lote['atualizado_em']);
    if ($inicio && $fim && $fim >= $inicio) {
        $tempoSegundos = $fim - $inicio;
    }
}

// Resumo do processamento (para o gráfico)
$stmt = $pdo->prepare("
    SELECT corrigidas, defeituosas, repetidas, total
    FROM resumo_lote
    WHERE lote_id = :lote_id
");
$stmt->execute([':lote_id' => $loteId]);
$resumo = $stmt->fetch(PDO::FETCH_ASSOC);

// Logs por folha (tabela detalhe)
$stmt = $pdo->prepare("
    SELECT mensagem, tipo_folha, pagina, atualizado_em
    FROM paginas_lote
    WHERE lote_id = :lote_id
    ORDER BY pagina ASC, atualizado_em ASC
");
$stmt->execute([':lote_id' => $loteId]);
$paginas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Pacote para análise INSE :: Análise Automática</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
    <style>
        body { background-color: #f9f9f9; }
        #main { margin-top: 72px; padding: 2rem; }
        .muted { color: #999; font-size: 0.9em; }
        .center-block { text-align: center; padding: 3rem 0; color: #777; }
    </style>
</head>
<body>
<div style="min-height: 100vh; display:flex; flex-direction:column">

    <!-- Menu superior -->
    <header>
        <div class="ui top fixed small menu">
            <a class="logo header item" href="index.php">Laboratório</a>
            <a class="blue active item" href="fila.php">Análise INSE</a>
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
                Pacote para análise INSE :: Análise Automática
                <div class="sub header">
                    Informações detalhadas do lote <strong><?= htmlspecialchars($lote['lote_id']) ?></strong>
                </div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar esquerda fixa da aplicação -->
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
                </nav>
            </div>

            <!-- Coluna central + coluna do gráfico -->
            <div class="thirteen wide column">
                <div class="ui stackable grid">
                    <!-- Coluna principal (informações, defeituosas, logs) -->
                    <div class="twelve wide column">

                        <!-- Informações do pacote -->
                        <div class="ui segment">
                            <h4 class="ui dividing header">
                                <i class="info circle icon"></i>
                                <div class="content">Informações do Pacote</div>
                            </h4>

                            <table class="ui celled table">
                                <tbody>
                                <tr>
                                    <td style="width:220px"><strong>Descrição do arquivo:</strong></td>
                                    <td><?= htmlspecialchars($lote['lote_id']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Carregamento:</strong></td>
                                    <td>
                                        Carregado em <?= date('d/m/Y \à\s H:i:s', strtotime($lote['criado_em'])) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Processamento:</strong></td>
                                    <td>
                                        <?php if ($lote['status'] === 'concluido'): ?>
                                            Processamento concluído em <?= date('d/m/Y \à\s H:i:s', strtotime($lote['atualizado_em'])) ?>
                                            <?php if ($tempoSegundos !== null): ?>
                                                <br><span class="muted">(levou <?= $tempoSegundos ?> segundos)</span>
                                            <?php endif; ?>
                                        <?php elseif ($lote['status'] === 'em_processamento'): ?>
                                            Em processamento desde <?= date('d/m/Y \à\s H:i:s', strtotime($lote['atualizado_em'])) ?>
                                        <?php elseif ($lote['status'] === 'erro'): ?>
                                            Erro no processamento em <?= date('d/m/Y \à\s H:i:s', strtotime($lote['atualizado_em'])) ?>
                                        <?php else: ?>
                                            Aguardando processamento
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                </tbody>
                            </table>
                        </div>

                        <!-- Folhas defeituosas -->
                        <div class="ui segment">
                            <h4 class="ui dividing header">
                                Folhas Defeituosas
                            </h4>

                            <?php
                            $temErrosPaginas = $resumo && ((int)$resumo['defeituosas'] > 0 || (int)$resumo['repetidas'] > 0);
                            if ($lote['status'] === 'concluido' && !$temErrosPaginas && empty($lote['mensagem_erro'])): ?>
                                <div class="center-block">
                                    <i class="huge thumbs up outline icon"></i>
                                    <h3>Tudo certo!</h3>
                                    <p>Todas as folhas deste arquivo foram processadas sem problemas.</p>
                                </div>
                            <?php elseif ($lote['status'] === 'erro' || $temErrosPaginas): ?>
                                <div class="ui negative message">
                                    <div class="header">Ocorreram erros no processamento.</div>
                                    <?php if (!empty($lote['mensagem_erro'])): ?>
                                        <p><?= nl2br(htmlspecialchars($lote['mensagem_erro'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ($resumo): ?>
                                        <p class="muted">
                                            Corrigidas: <?= (int)$resumo['corrigidas'] ?>,
                                            Defeituosas: <?= (int)$resumo['defeituosas'] ?>,
                                            Repetidas: <?= (int)$resumo['repetidas'] ?>.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="center-block">
                                    <i class="large info circle icon"></i>
                                    <p>Aguardando ou em processamento. Atualize a página em alguns instantes.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Logs do pacote -->
                        <div class="ui segment">
                            <h4 class="ui dividing header">
                                Logs do pacote
                            </h4>
                            <table class="ui celled table">
                                <thead>
                                <tr>
                                    <th>Log</th>
                                    <th>Tipo de folha</th>
                                    <th>Página</th>
                                    <th>Data</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($paginas): ?>
                                    <?php foreach ($paginas as $p): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p['mensagem']) ?></td>
                                            <td><?= htmlspecialchars($p['tipo_folha']) ?></td>
                                            <td><?= (int)$p['pagina'] ?></td>
                                            <td><?= date('d/m/Y H:i:s', strtotime($p['atualizado_em'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="muted">
                                            Nenhum log de página registrado para este lote até o momento.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <a class="ui button" href="fila.php">
                            <i class="angle left icon"></i> Voltar para a Fila de Correção
                        </a>
                    </div>

                    <!-- Coluna do gráfico (card único) -->
                    <div class="four wide column">
                        <div class="ui segment">
                            <h4 class="ui dividing header">
                                Resumo do Processamento
                            </h4>
                            <div class="center-block">
                                <canvas id="resumoChart" width="220" height="220"></canvas>
                                <?php if ($resumo): ?>
                                    <div style="margin-top:1rem">
                                        <div class="ui label">
                                            <?= (int)$resumo['total'] ?> folhas no arquivo
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="muted">Aguardando dados de processamento.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div> <!-- grid interna -->
            </div>
        </div>
    </main>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    $('.ui.dropdown').dropdown();

    <?php if ($resumo): ?>
    const ctx = document.getElementById('resumoChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Corrigidas', 'Defeituosas', 'Repetidas'],
            datasets: [{
                data: [
                    <?= (int)$resumo['corrigidas'] ?>,
                    <?= (int)$resumo['defeituosas'] ?>,
                    <?= (int)$resumo['repetidas'] ?>
                ],
                backgroundColor: ['#21ba45', '#db2828', '#1b1c1d'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            cutout: '60%',
            plugins: {
                legend: { position: 'top' }
            }
        }
    });
    <?php endif; ?>
</script>
</body>
</html>
