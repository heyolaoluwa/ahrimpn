<?php
// api/stats.php — dashboard stats (public)
require_once 'config.php';

$db = getDB();

$stats = [
    'members'      => (int)$db->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn(),
    'events'       => (int)$db->query("SELECT COUNT(*) FROM events WHERE status='Upcoming'")->fetchColumn(),
    'publications' => (int)$db->query("SELECT COUNT(*) FROM publications WHERE status='Published'")->fetchColumn(),
    'certifications'=> (int)$db->query("SELECT COUNT(*) FROM certifications")->fetchColumn(),
    'state_updates'=> (int)$db->query("SELECT COUNT(*) FROM state_updates WHERE status='Published'")->fetchColumn(),
    'gallery'      => (int)$db->query("SELECT COUNT(*) FROM gallery")->fetchColumn(),
];

respond($stats);
