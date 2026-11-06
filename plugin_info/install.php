<?php
require_once __DIR__ . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/presencehabituelle.class.php';

function presencehabituelle_install() {
  presencehabituelle::demarrer();
}

function presencehabituelle_update() {
  presencehabituelle::demarrer();
}

// Appelée aussi à la simple désactivation (plugin::setIsEnable) : on arrête l'écoute, les relevés restent.
// À la désinstallation, le core efface la configuration ; la table restante est vidée à la réinstallation, ou par « Tout effacer ».
function presencehabituelle_remove() {
  presencehabituelle::arreter();
}
