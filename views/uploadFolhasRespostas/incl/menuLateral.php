<nav class="ui vertical fluid tabular menu">
    <div class="header item">ANÁLISE INSE</div>
    <a class="item <?= /** @var $controller */
    ($controller->submenuAtivo === 'folhasResps') ? 'active' : '' ?>" href="index.php?action=verFila">
        Fila de Análise
        <i class="horizontal ellipsis icon"></i>
    </a>
    <a class="item <?= ($controller->submenuAtivo === 'novaCorrecao') ? 'active' : '' ?>" href="index.php?action=upload">
        Enviar para Análise
        <i class="upload icon"></i>
    </a>
    <a class="item <?= ($controller->submenuAtivo === 'meusEnvios') ? 'active' : '' ?>" href="index.php?action=meusEnvios">
        Meus envios
        <i class="folder open icon"></i>
    </a>
</nav>

