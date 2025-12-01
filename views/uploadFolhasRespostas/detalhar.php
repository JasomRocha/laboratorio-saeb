<?php
/* @var $controller UploadFolhasRespostasController */
/* @var $pacote array */
/* @var $resumo array|false */
/* @var $folhasProblema array */
/* @var $paginas array */
/* @var $cadernosIncompletos array */
/* @var $tempoSegundos int|null */
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
        .center-block {
            text-align: center;
            padding: 3rem 0;
            color: #777;
        }
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
                <?= htmlspecialchars($controller->pgTitulo) ?>
                <div class="sub header">
                    Informações detalhadas do lote <strong><?= htmlspecialchars($pacote['lote_id']) ?></strong>
                </div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar esquerda -->
            <div class="three wide column">
                <?php require __DIR__ . '/incl/menuLateral.php'; ?>
            </div>

            <!-- Coluna central -->
            <div class="thirteen wide column">
                <div class="ui stackable grid">
                    <!-- Coluna principal -->
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
                                    <td style="width:220px"><strong>Descrição do arquivo</strong></td>
                                    <td><?= htmlspecialchars($pacote['lote_id']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Carregamento</strong></td>
                                    <td>
                                        Carregado em <?= date('d/m/Y H:i:s', strtotime($pacote['criado_em'])) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Processamento</strong></td>
                                    <td>
                                        <?php if ($pacote['status'] === 'concluido'): ?>
                                            Processamento concluído em <?= date('d/m/Y H:i:s', strtotime($pacote['atualizado_em'])) ?>
                                            <?php if ($tempoSegundos !== null): ?>
                                                <br><span class="muted">levou <?= $tempoSegundos ?> segundos</span>
                                            <?php endif; ?>
                                        <?php elseif ($pacote['status'] === 'em_processamento'): ?>
                                            Em processamento desde <?= date('d/m/Y H:i:s', strtotime($pacote['atualizado_em'])) ?>
                                        <?php elseif ($pacote['status'] === 'erro'): ?>
                                            Erro no processamento em <?= date('d/m/Y H:i:s', strtotime($pacote['atualizado_em'])) ?>
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
                            <h4 class="ui dividing header">Folhas Defeituosas</h4>

                            <?php
                            $temErrosPaginas = !empty($folhasProblema);
                            ?>

                            <?php if ($pacote['status'] === 'concluido' && !$temErrosPaginas && empty($pacote['mensagem_erro'])): ?>
                                <div class="center-block">
                                    <i class="huge thumbs up outline icon"></i>
                                    <h3>Tudo certo!</h3>
                                    <p>Todas as folhas deste arquivo foram processadas sem problemas.</p>
                                </div>
                            <?php elseif ($pacote['status'] === 'erro' || $temErrosPaginas || !empty($pacote['mensagem_erro'])): ?>
                                <div class="ui negative message">
                                    <div class="header">Ocorreram erros no processamento.</div>
                                    <?php if (!empty($pacote['mensagem_erro'])): ?>
                                        <p><?= nl2br(htmlspecialchars($pacote['mensagem_erro'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ($resumo): ?>
                                        <p class="muted">
                                            Corrigidas: <?= (int)$resumo['corrigidas'] ?>,
                                            Defeituosas: <?= (int)$resumo['defeituosas'] ?>,
                                            Repetidas: <?= (int)$resumo['repetidas'] ?>.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <?php if ($folhasProblema): ?>
                                    <h5 class="ui header">Lista de folhas com problema</h5>
                                    <table class="ui compact celled table">
                                        <thead>
                                        <tr>
                                            <th>Página</th>
                                            <th>Status</th>
                                            <th>Mensagem</th>
                                            <th>Caminho / Download</th>
                                            <th>Caderno</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($folhasProblema as $f): ?>
                                            <tr>
                                                <td><?= (int)$f['pagina'] ?></td>
                                                <td><?= htmlspecialchars($f['status']) ?></td>
                                                <td><?= htmlspecialchars($f['mensagem']) ?></td>
                                                <td>
                                                    <?php if (!empty($f['caminho_folha'])): ?>
                                                        <?php $path = $f['caminho_folha']; ?>
                                                        <a href="<?= htmlspecialchars($path) ?>" target="_blank">
                                                            <?= htmlspecialchars(basename($path)) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="muted">Sem caminho registrado</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($f['caderno_hash'])): ?>
                                                        <?= htmlspecialchars($f['caderno_hash']) ?>
                                                    <?php else: ?>
                                                        <span class="muted">Sem QR</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>

                                <?php if ($cadernosIncompletos): ?>
                                    <h5 class="ui header" style="margin-top:1.5rem">Cadernos incompletos detectados</h5>
                                    <table class="ui compact celled table">
                                        <thead>
                                        <tr>
                                            <th>Caderno (hash)</th>
                                            <th>Total de páginas lidas</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($cadernosIncompletos as $c): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($c['caderno_hash']) ?></td>
                                                <td><?= (int)$c['total_paginas'] ?></td>
                                                <td><?= htmlspecialchars($c['status']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="center-block">
                                    <i class="large info circle icon"></i>
                                    <p>Aguardando ou em processamento. Atualize a página em alguns instantes.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Logs do pacote -->
                        <div class="ui segment">
                            <h4 class="ui dividing header">Logs do pacote</h4>
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

                        <a class="ui button" href="verFila.php">
                            <i class="angle left icon"></i> Voltar para a Fila de Correção
                        </a>
                    </div>

                    <!-- Coluna do gráfico (lateral direita) -->
                    <div class="four wide column">
                        <div class="ui segment">
                            <h4 class="ui dividing header">Resumo do Processamento</h4>
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
                </div><!-- grid interna -->
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
    // Gráfico de rosca
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
                legend: {
                    position: 'top'
                }
            }
        }
    });
    <?php endif; ?>
</script>
</body>
</html>

