<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Ferme le conteneur .cu-wrap ouvert dans admin/partials/header.php.
// Grâce à ça, le contenu de chaque vue (.cu-page) est un descendant de
// .cu-wrap et hérite bien des variables CSS du thème (--cu-bg, --cu-text…).
?>
</div><!-- /.cu-wrap -->
