<?php
// ==============================================================================
// SCRIPT DE LIMPIEZA COMPLETA (Incluye Clientes/Terceros)
// ==============================================================================
// Borra TODOS los registros creados "HOY" en las 6 tablas afectadas.
// ==============================================================================

// CONFIGURACIÓN
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'dol_ikonik';

// OBTENER FECHA DE HOY
$fecha_hoy = date('Y-m-d'); // Ej: 2025-11-24
$patron_fecha = $fecha_hoy . '%'; // Para el LIKE SQL

echo "<h1>Limpieza TOTAL del día: <span style='color:red'>$fecha_hoy</span></h1>";
echo "<p>Iniciando borrado en cascada (Hijos -> Padres -> Abuelos)...</p>";

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("<h3 style='color:red'>Error de conexión: " . $e->getMessage() . "</h3>");
}

// --- FUNCIÓN DE BORRADO ---
function borrar_tabla($pdo, $tabla, $columna_fecha, $patron)
{
    try {
        $sql = "DELETE FROM $tabla WHERE $columna_fecha LIKE ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$patron]);
        $count = $stmt->rowCount();

        $color = $count > 0 ? "red" : "gray";
        echo "Tabla <strong>$tabla</strong>: <span style='color:$color'>$count registros eliminados</span>.<br>";
        return $count;
    } catch (PDOException $e) {
        echo "Error borrando en $tabla: " . $e->getMessage() . "<br>";
        return 0;
    }
}

// 1. Datos Financieros (Hijo de Sitios)
borrar_tabla($pdo, 'presupuestos_indicadores', 'fecha_registro', $patron_fecha);

// 2. Extras de Proyectos (Hijo de Proyectos)
borrar_tabla($pdo, 'llx_projet_extrafields', 'tms', $patron_fecha);

// 3. Proyectos (Padre de Extras, Hijo de Terceros)
// IMPORTANTE: Borrar esto libera a los clientes para poder ser borrados después
borrar_tabla($pdo, 'llx_projet', 'datec', $patron_fecha);

// 4. Extras de Sitios
borrar_tabla($pdo, 'llx_socpeople_extrafields', 'tms', $patron_fecha);

// 5. Sitios (Contactos)
borrar_tabla($pdo, 'llx_socpeople', 'datec', $patron_fecha);

// 6. CLIENTES / TERCEROS (Nuevo paso)
// Solo podemos borrarlos si ya no tienen proyectos vinculados (hecho en paso 3)
borrar_tabla($pdo, 'llx_societe', 'datec', $patron_fecha);

echo "<h3>✅ Base de datos limpia como una patena.</h3>";
echo "<a href='../migracion_pdo.php'>Volver a intentar la Migración</a>";
