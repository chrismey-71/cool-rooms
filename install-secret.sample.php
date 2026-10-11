<?php
declare(strict_types=1);

return [
  // Vorlage: In install.php einen sicheren 64-stelligen Setup-Code erzeugen und hier einsetzen.
  'token' => 'HIER_DEN_64-STELLIGEN_SETUP-CODE_EINTRAGEN',

  // Den vom Hostinganbieter genannten Datenbankserver eintragen, häufig localhost.
  'db_host' => 'localhost',

  // Nur ändern, wenn der Hostinganbieter ausdrücklich einen anderen Port nennt.
  'db_port' => null,
];
