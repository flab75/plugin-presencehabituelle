<?php
require_once __DIR__ . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/presencehabituelle.class.php';

function presencehabituelle_install() {
  presencehabituelle::creerTable();
  presencehabituelle::majListener(); // réactivation : le suivi gardé en configuration reprend
}

function presencehabituelle_update() {
  presencehabituelle::creerTable();
  presencehabituelle::majListener();
}

function presencehabituelle_remove() {
  presencehabituelle::supprimerTout(); // listener + table des évènements : rien ne reste sur la box
}
