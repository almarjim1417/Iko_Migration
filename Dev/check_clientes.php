<?php
// ==============================================================================
// DIAGNÓSTICO DE CLIENTES COMPLETO (Sin Límite)
// ==============================================================================
// Este script NO modifica la base de datos. Solo lee y compara.
// ==============================================================================

// AUMENTAR TIEMPO Y MEMORIA (Por si el archivo es muy grande)
set_time_limit(0);
ini_set('memory_limit', '1024M');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'dol_ikonik';
$file_projects = '../import_proyectos.csv';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset("utf8");

echo "<h1>Diagnóstico COMPLETO de Clientes</h1>";
echo "<p>Umbral de aceptación configurado: <strong>85%</strong></p>";

// --- FUNCIONES ---
function normalizar_texto($texto)
{
    if (empty($texto)) return "";
    $texto = mb_convert_encoding($texto, 'UTF-8', 'auto');
    $acentos = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n'];
    $texto = strtr($texto, $acentos);
    return trim(mb_strtolower($texto, 'UTF-8'));
}

// LA FUNCIÓN CLAVE QUE QUEREMOS PROBAR
function buscar_mejor_coincidencia($nombre_buscado, $lista_clientes_bd)
{
    $mejor_id = null;
    $mayor_similitud = 0;
    $nombre_encontrado = "";

    $buscado_norm = normalizar_texto($nombre_buscado);

    foreach ($lista_clientes_bd as $nombre_bd_norm => $datos) {
        // similar_text devuelve el % de parecido en la variable $porcentaje
        similar_text($buscado_norm, $nombre_bd_norm, $porcentaje);

        if ($porcentaje > $mayor_similitud) {
            $mayor_similitud = $porcentaje;
            $mejor_id = $datos['id'];
            $nombre_encontrado = $datos['nombre_real'];
        }
    }

    return ['id' => $mejor_id, 'nombre' => $nombre_encontrado, 'score' => $mayor_similitud];
}

// 1. Cargar Clientes de la BD
$clientes_bd = [];
$sql = "SELECT rowid, nom FROM llx_societe";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $norm = normalizar_texto($row['nom']);
    $clientes_bd[$norm] = ['id' => $row['rowid'], 'nombre_real' => $row['nom']];
}
echo "<p>Clientes en BD analizados: " . count($clientes_bd) . "</p>";

// 2. Tabla de Resultados
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#f0f0f0; text-align:left;'>
        <th>Fila</th>
        <th>Nombre en EXCEL</th>
        <th>Búsqueda EXACTA</th>
        <th>Búsqueda INTELIGENTE (Similitud)</th>
        <th>¿Qué haría el script v5.0?</th>
      </tr>";

if (file_exists($file_projects) && ($handle = fopen($file_projects, "r")) !== FALSE) {
    fgetcsv($handle, 0, ","); // Saltar cabecera
    $fila = 1;

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        $fila++;
        $nombre_excel = trim($data[7] ?? ''); // Columna Propietario

        if (empty($nombre_excel)) continue;

        $nombre_busqueda = normalizar_texto($nombre_excel);

        echo "<tr>";
        echo "<td>$fila</td>";
        echo "<td><strong>$nombre_excel</strong></td>";

        // 1. Intento Exacto
        if (isset($clientes_bd[$nombre_busqueda])) {
            echo "<td style='background:#dff0d8; color:green'>✅ EXACTO<br><small>(" . $clientes_bd[$nombre_busqueda]['nombre_real'] . ")</small></td>";
            echo "<td style='color:gray'>-</td>";
            echo "<td><strong>Usar Existente</strong></td>";
        }
        // 2. Intento Inteligente
        else {
            echo "<td style='background:#f2dede; color:red'>❌ No encontrado</td>";

            // Probamos la IA
            $resultado = buscar_mejor_coincidencia($nombre_excel, $clientes_bd);
            $score = number_format($resultado['score'], 2);

            if ($resultado['score'] > 85) {
                echo "<td style='background:#d9edf7;'>
                        💡 <strong>DETECTADO:</strong><br>
                        '{$resultado['nombre']}'<br>
                        Similitud: <strong>$score%</strong>
                      </td>";
                echo "<td style='background:#dff0d8; color:green'><strong>✅ ASIGNAR AUTOMÁTICAMENTE</strong><br><small>Ahorras crear duplicado</small></td>";
            } elseif ($resultado['score'] > 60) {
                echo "<td style='background:#fcf8e3;'>
                        🤔 <strong>DUDOSO:</strong><br>
                        '{$resultado['nombre']}'<br>
                        Similitud: <strong>$score%</strong><br>
                        <small>(Umbral no superado)</small>
                      </td>";
                echo "<td style='background:#f2dede; color:blue'><strong>🆕 CREAR NUEVO CLIENTE</strong><br><small>Demasiado riesgo de fallo</small></td>";
            } else {
                echo "<td>No hay coincidencias cercanas ($score%)</td>";
                echo "<td style='color:blue'><strong>🆕 CREAR NUEVO CLIENTE</strong></td>";
            }
        }
        echo "</tr>";

        // ¡LÍMITE ELIMINADO! Recorrerá todo el archivo.
    }
    fclose($handle);
    echo "</table>";
} else {
    echo "No encuentro el archivo CSV: $file_projects";
}
