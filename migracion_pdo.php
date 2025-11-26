<?php
// ==============================================================================
// MIGRACIÓN DOLIBARR v9.0 (BLINDADA CONTRA RECARGAS)
// ==============================================================================
// Estrategia: "Preguntar antes de disparar".
// Realiza consultas SQL de verificación justo antes de cada inserción.
// ==============================================================================

// CONFIGURACIÓN
set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(1);

$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'dol_ikonik';

$files_sites = [
    'sites_2015.csv',
    'sites_2016.csv',
    'sites_2017.csv',
    'sites_2018.csv',
    'sites_2019.csv',
    'sites_2020.csv',
    'sites_2021.csv'
];
$file_projects = 'import_proyectos.csv';

$id_user_creat = 1;

echo "<h1>Migración v9.0</h1>";

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "<p style='color:green'>Conexión establecida.</p>";
} catch (\PDOException $e) {
    die("Error BD: " . $e->getMessage());
}

// --- FUNCIONES ---
function limpiar_moneda($valor)
{
    if (empty($valor)) return 0;
    $valor = str_replace(['€', ' '], '', $valor);
    if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (strpos($valor, ',') !== false) {
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}
function get_status_site($texto)
{
    return (stripos($texto, 'Inactivo') !== false) ? 0 : 1;
}
function get_estado_proyecto($texto)
{
    return (stripos($texto, 'borrador') !== false) ? 0 : 1;
}

// ==============================================================================
// PASO 1: SITIOS (CSV) - CON VERIFICACIÓN SQL
// ==============================================================================
echo "<h2>Paso 1: Procesando Sitios...</h2>";
flush();

// Consultas de verificación
$check_site = $pdo->prepare("SELECT rowid FROM llx_socpeople WHERE ref_ext = ? LIMIT 1");
$check_site_name = $pdo->prepare("SELECT rowid FROM llx_socpeople WHERE lastname = ? LIMIT 1");

// Inserción
$sql_site = "INSERT INTO llx_socpeople (datec, ref_ext, lastname, address, town, statut, note_private, fk_user_creat, entity) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, 1)";
$stmt_site = $pdo->prepare($sql_site);
$sql_site_extra = "INSERT INTO llx_socpeople_extrafields (fk_object, ari1, ari2, ari3, lat, long_, rates, fechamontaje, fechadesmontaje, nom_marketing) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt_site_extra = $pdo->prepare($sql_site_extra);

$nuevos = 0;
$omitidos = 0;

foreach ($files_sites as $archivo) {
    if (!file_exists($archivo)) continue;
    $handle = fopen($archivo, "r");
    fgetcsv($handle, 0, ",");

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        $lastname = trim($data[3] ?? '');
        $ref_ext = trim($data[0] ?? '');

        if (empty($lastname)) continue;

        // 1. CHECK DOBLE: Por Referencia O Por Nombre
        $existe = false;

        if (!empty($ref_ext)) {
            $check_site->execute([$ref_ext]);
            if ($check_site->fetch()) $existe = true;
        }

        if (!$existe) {
            $check_site_name->execute([$lastname]);
            if ($check_site_name->fetch()) $existe = true;
        }

        if ($existe) {
            $omitidos++;
            continue; // ¡SALTAMOS SI YA EXISTE!
        }

        // Datos
        $town = $data[1] ?? '';
        $address = $data[2] ?? '';
        $rates = limpiar_moneda($data[7] ?? 0);
        $ari1 = (float)str_replace(',', '.', $data[10] ?? 0);
        $ari2 = (float)str_replace(',', '.', $data[11] ?? 0);
        $ari3 = (float)str_replace(',', '.', $data[12] ?? 0);
        $lat = (float)str_replace(',', '.', $data[19] ?? 0);
        $long_ = (float)str_replace(',', '.', $data[20] ?? 0);
        $statut = get_status_site($data[16] ?? '');
        $fechamontaje = !empty($data[14]) ? date('Y-m-d', strtotime(str_replace('/', '-', $data[14]))) : null;
        $fechadesmontaje = !empty($data[15]) ? date('Y-m-d', strtotime(str_replace('/', '-', $data[15]))) : null;
        $note_private = $data[17] ?? '';

        try {
            $stmt_site->execute([$ref_ext, $lastname, $address, $town, $statut, $note_private, $id_user_creat]);
            $site_id = $pdo->lastInsertId();
            $stmt_site_extra->execute([$site_id, $ari1, $ari2, $ari3, $lat, $long_, $rates, $fechamontaje, $fechadesmontaje, $lastname]);
            $nuevos++;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) $omitidos++;
        }
    }
    fclose($handle);
}
echo "<p>Sitios: <strong>+$nuevos</strong> | Omitidos: $omitidos</p>";
flush();


// ==============================================================================
// PASO 2: PROYECTOS + CLIENTES (CON DOBLE CHECK)
// ==============================================================================
echo "<h2>Paso 2: Proyectos y Clientes...</h2>";
echo "<div style='border:1px solid #ccc; padding:10px; max-height:400px; overflow-y:scroll;'>";

if (file_exists($file_projects)) {
    // Consultas de Verificación (La clave del éxito)
    $check_proj = $pdo->prepare("SELECT rowid FROM llx_projet WHERE ref = ? LIMIT 1");
    $check_client = $pdo->prepare("SELECT rowid FROM llx_societe WHERE nom = ? LIMIT 1");
    $find_site = $pdo->prepare("SELECT rowid FROM llx_socpeople WHERE lastname = ? LIMIT 1");

    // Inserciones
    $stmt_new_client = $pdo->prepare("INSERT INTO llx_societe (nom, client, datec, fk_user_creat, entity, status) VALUES (?, 1, NOW(), ?, 1, 1)");
    $stmt_new_site = $pdo->prepare("INSERT INTO llx_socpeople (datec, ref_ext, lastname, address, town, statut, note_private, fk_user_creat, entity) VALUES (NOW(), ?, ?, '', '', 1, 'Generado Auto por Proyecto', ?, 1)");
    $stmt_site_extra = $pdo->prepare("INSERT INTO llx_socpeople_extrafields (fk_object, nom_marketing) VALUES (?, ?)");

    $stmt_proj = $pdo->prepare("INSERT INTO llx_projet (ref, title, fk_soc, dateo, datee, datec, note_public, fk_statut, opp_percent, fk_user_creat, entity) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 1)");
    $stmt_proj_extra = $pdo->prepare("INSERT INTO llx_projet_extrafields (fk_object, tipo, fk_emplazamiento) VALUES (?, ?, ?)");
    $stmt_money = $pdo->prepare("INSERT INTO presupuestos_indicadores (fk_emplazamiento, venta_prevista_vs, coste_previsto_vs, venta_presupuestada_vpr, costes_en_presupuesto_gpr) VALUES (?, ?, ?, ?, ?)");

    $handle = fopen($file_projects, "r");
    fgetcsv($handle, 0, ",");

    $proy_nuevos = 0;
    $proy_omitidos = 0;
    $cli_nuevos = 0;
    $sitios_nuevos = 0;
    $fila = 1;

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        $fila++;
        if ($fila % 50 == 0) {
            echo ". ";
            flush();
        }

        $ref = trim($data[0] ?? '');
        if (empty($ref)) continue;

        // --- CHECK 1: ¿EXISTE YA EL PROYECTO? ---
        $check_proj->execute([$ref]);
        if ($check_proj->fetch()) {
            $proy_omitidos++;
            continue; // Si existe, ADIÓS. Pasamos al siguiente.
        }

        $title = trim($data[2] ?? '');

        // --- LÓGICA SITIO (Buscar o Crear) ---
        $fk_emplazamiento = null;

        // Buscamos sitio por nombre
        $find_site->execute([$title]);
        $row_site = $find_site->fetch(PDO::FETCH_ASSOC);

        if ($row_site) {
            $fk_emplazamiento = $row_site['rowid'];
        } else {
            // NO EXISTE -> LO CREAMOS
            try {
                $ref_site_auto = 'AUTO-' . md5($title . time()); // Ref única para evitar choques
                $stmt_new_site->execute([$ref_site_auto, $title, $id_user_creat]);
                $fk_emplazamiento = $pdo->lastInsertId();
                $stmt_site_extra->execute([$fk_emplazamiento, $title]);
                $sitios_nuevos++;
                echo "<small style='color:purple'>[Sitio Nuevo] $title</small><br>";
            } catch (PDOException $e) {
                // Si falla (quizás lo creó otro proceso milésimas antes), intentamos buscarlo de nuevo
                $find_site->execute([$title]);
                $row_retry = $find_site->fetch();
                if ($row_retry) $fk_emplazamiento = $row_retry['rowid'];
            }
        }

        if (!$fk_emplazamiento) continue;

        // --- LÓGICA CLIENTE (Buscar o Crear) ---
        $nombre_propietario = trim($data[7] ?? '');
        if (empty($nombre_propietario)) $nombre_propietario = $ref; // Si vacío, usa Ref Proyecto

        $fk_soc = 0;

        // Buscamos cliente por nombre exacto
        $check_client->execute([$nombre_propietario]);
        $row_cli = $check_client->fetch(PDO::FETCH_ASSOC);

        if ($row_cli) {
            $fk_soc = $row_cli['rowid'];
        } else {
            // NO EXISTE -> CREAR
            try {
                $stmt_new_client->execute([$nombre_propietario, $id_user_creat]);
                $fk_soc = $pdo->lastInsertId();
                $cli_nuevos++;
                echo "<small style='color:blue'>[Cliente Nuevo] $nombre_propietario</small><br>";
            } catch (PDOException $e) {
                // Fallo silencioso
            }
        }

        // --- INSERTAR PROYECTO ---
        $dateo = !empty($data[23]) ? date('Y-m-d', strtotime(str_replace('/', '-', $data[23]))) : null;
        $datee = !empty($data[24]) ? date('Y-m-d', strtotime(str_replace('/', '-', $data[24]))) : null;
        $note_public = $data[26] ?? '';
        $fk_statut = (stripos($data[19] ?? '', 'borrador') !== false) ? 0 : 1;
        $opp_percent = (float)str_replace(['%', ','], ['', '.'], $data[20] ?? 0);

        try {
            $stmt_proj->execute([$ref, $title, $fk_soc, $dateo, $datee, $note_public, $fk_statut, $opp_percent, $id_user_creat]);
            $proj_id = $pdo->lastInsertId();

            $tipo = $data[6] ?? '';
            $stmt_proj_extra->execute([$proj_id, $tipo, $fk_emplazamiento]);

            $sales = $data[14] ?? '';
            $profit = $data[15] ?? '';
            $ratecard = $data[11] ?? '';
            $prod = $data[13] ?? '';
            $stmt_money->execute([$fk_emplazamiento, $sales, $profit, $ratecard, $prod]);

            $proy_nuevos++;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) $proy_omitidos++;
            else echo "<small style='color:red'>Error: " . $e->getMessage() . "</small><br>";
        }
    }
    fclose($handle);
    echo "</div>";
    echo "<h3>¡PROCESO BLINDADO COMPLETADO!</h3>";
    echo "<ul>";
    echo "<li>Proyectos Nuevos: <strong>$proy_nuevos</strong></li>";
    echo "<li>Proyectos Ya Existentes (Omitidos): <strong>$proy_omitidos</strong></li>";
    echo "</ul>";
} else {
    echo "<h3 style='color:red'>No encuentro $file_projects</h3>";
}
