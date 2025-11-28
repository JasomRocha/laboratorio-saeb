<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$pdo = new PDO(
        'mysql:host=localhost;dbname=corretor_saeb;charset=utf8mb4',
        'root',
        'Clarinha1408',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// junta lotes_correcao com resumo_lote (se existir)
$stmt = $pdo->query("
    SELECT l.id,
           l.lote_id,
           l.s3_prefix,
           l.total_arquivos,
           l.status,
           l.mensagem_erro,
           l.criado_em,
           l.atualizado_em,
           r.corrigidas,
           r.defeituosas,
           r.repetidas,
           r.total AS total_paginas
    FROM lotes_correcao l
    LEFT JOIN resumo_lote r ON r.lote_id = l.lote_id
    ORDER BY l.criado_em DESC
");
$lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fila de Correção :: Correção Automática :: Plataforma SAEB</title>
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
        <div class="ui top fixed small menu">
            <a class="logo header item" href="index.php">Laboratório</a>
            <a class="blue active item" href="fila.php">Análise INSE</a>

            <div class="right menu">
                <div class="ui dropdown item" style="text-align: center">
                    Conectado como<br>
                    <strong>qstione</strong>
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
                Fila de Análise :: Análise Automática
                <div class="sub header">Permite fazer o upload de arquivos de questionários</div>
            </div>
        </h1>

        <div class="ui stackable grid">
            <!-- Sidebar -->
            <div class="three wide column">
                <nav class="ui vertical fluid tabular menu">
                    <div class="header item">ANÁLISE INSE</div>
                    <a class="item active" href="fila.php">
                        Fila de Análise
                        <i class="horizontal ellipsis icon"></i>
                    </a>
                    <a class="item" href="index.php">
                        Enviar para Análise
                        <i class="upload icon"></i>
                    </a>
                </nav>
            </div>

            <!-- Área principal -->
            <div class="thirteen wide column">

                <!-- Barra de ações -->
                <nav class="ui menu">
                    <div class="borderless item">
                        <strong>(<?= count($lotes) ?> pacotes na listagem)</strong>
                    </div>
                    <div class="right item">
                        <a class="ui primary right labeled icon basic button" href="index.php">
                            <i class="upload icon"></i>Enviar novo pacote
                        </a>
                    </div>
                </nav>

                <!-- Tabela de Pacotes -->
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
                                $rowClass = 'positive';
                                $iconClass = 'check green icon circular inverted';
                                break;
                            case 'erro':
                                $rowClass = 'negative';
                                $iconClass = 'warning orange circular inverted icon';
                                break;
                            case 'em_processamento':
                                $rowClass = '';
                                $iconClass = 'sync blue icon circular inverted loading';
                                break;
                            default:
                                $rowClass = '';
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
                                <?= htmlspecialchars($l['lote_id']) ?><br>
                                <span class="muted">
                                    Carregado em <?= date('d/m/Y \à\s H:i:s', strtotime($l['criado_em'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($status === 'concluido'): ?>
                                    <i class="check icon"></i>
                                    Concluído em <?= date('d/m/Y \à\s H:i:s', strtotime($l['atualizado_em'])) ?><br>
                                    <a class="ui green small label">
                                        <?= $corrigidas ?> corrigidas
                                    </a>
                                    <?php if ($defeituosas > 0): ?>
                                        <a class="ui red small label">
                                            <?= $defeituosas ?> defeituosas
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($repetidas > 0): ?>
                                        <a class="ui black small label">
                                            <?= $repetidas ?> repetidas
                                        </a>
                                    <?php endif; ?>
                                <?php elseif ($status === 'erro'): ?>
                                    <i class="warning icon"></i>
                                    Erro em <?= date('d/m/Y \à\s H:i:s', strtotime($l['atualizado_em'])) ?><br>
                                    <a class="ui red small label">
                                        <?= $totalPaginas ?> páginas com problema
                                    </a>
                                <?php elseif ($status === 'em_processamento'): ?>
                                    <i class="sync loading icon"></i>Processando...
                                <?php else: ?>
                                    <i class="clock icon"></i>Aguardando processamento
                                <?php endif; ?>
                            </td>
                            <td class="center aligned single line collapsing">
                                <span class="muted">
                                    <?= $totalPaginas ?> páginas no arquivo
                                </span>
                            </td>
                            <td class="right aligned">
                                <div class="ui small buttons">
                                    <?php if ($status === 'uploaded'): ?>
                                        <form action="coletar.php" method="post" style="display:inline">
                                            <input type="hidden" name="lote_id" value="<?= htmlspecialchars($l['lote_id']) ?>">
                                            <button class="ui compact primary button" type="submit">
                                                <i class="play icon"></i> Coletar respostas
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form action="recalcular.php" method="post" style="display:inline">
                                            <input type="hidden" name="lote_id" value="<?= htmlspecialchars($l['lote_id']) ?>">
                                            <button class="ui compact orange button" type="submit">
                                                <i class="repeat icon"></i> Recalcular
                                            </button>
                                        </form>
                                        <a class="ui compact icon button" href="detalhar.php?lote_id=<?= urlencode($l['lote_id']) ?>">
                                            <i class="list icon"></i> detalhar
                                        </a>
                                        <a class="ui icon compact grey button"
                                           title="Baixar cópia do arquivo"
                                           href="download.php?lote_id=<?= urlencode($l['lote_id']) ?>">
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
    setTimeout(function(){ location.reload(); }, 5000);
</script>
</body>
</html>
