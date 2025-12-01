<nav class="ui vertical fluid tabular menu">
    <div class="header item">ANÁLISE INSE</div>
    <a class="item <?= ($controller->submenuAtivo === 'folhasResps') ? 'active' : '' ?>" href="verFila.php">
        Fila de Análise
        <i class="horizontal ellipsis icon"></i>
    </a>
    <a class="item <?= ($controller->submenuAtivo === 'novaCorrecao') ? 'active' : '' ?>" href="upload.php">
        Enviar para Análise
        <i class="upload icon"></i>
    </a>
    <a class="item" href="meus_envios.php">
        Meus envios
        <i class="folder open icon"></i>
    </a>
</nav>

