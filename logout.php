<?php
/** Termina a sessão. */

require_once __DIR__ . '/lib/bootstrap.php';

auth_logout('manual');
session_start();
flash('ok', 'Sessão terminada.');
redirect('login.php');
