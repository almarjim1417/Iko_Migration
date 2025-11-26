# Iko_Migration 🚀

**Herramienta de Ingeniería de Datos para Dolibarr**

Script PHP avanzado diseñado para la migración masiva, limpieza y vinculación de datos entre archivos Excel (CSV) y la base de datos de Dolibarr. Especialmente construido para gestionar la relación compleja entre **Proyectos (Emplazamientos)**, **Sitios Físicos (Direcciones)** y **Terceros (Clientes)**.

---

## 🧠 ¿Cómo funciona el Script? (Lógica Interna)

El script no se limita a copiar y pegar datos. Ejecuta un proceso de decisión inteligente en 3 fases para garantizar la integridad de la base de datos:

### 1. Fase de "Precarga y Memoria"
Antes de procesar una sola línea del Excel, el script lee la base de datos actual y carga en la memoria RAM:
* Todos los **Sitios** existentes.
* Todos los **Clientes** existentes.
* Todos los **Proyectos** existentes.
* *Objetivo:* Evitar consultas SQL repetitivas y detectar duplicados al instante.

### 2. Algoritmo de "Smart Matching" (IA Básica)
Al leer un nombre (de cliente o sitio) del Excel, el script intenta encontrarlo en Dolibarr usando tres niveles de búsqueda:
1.  **Normalización:** Convierte todo a minúsculas, elimina tildes y caracteres especiales (ej: *"Macià"* = *"macia"*).
2.  **Búsqueda Exacta:** Busca la coincidencia literal.
3.  **Lógica Difusa (Fuzzy Matching):** Si no es exacto, compara el texto con todos los registros de la BD. Si encuentra una similitud superior al **85%**, lo da por válido.
    * *Ejemplo:* Asigna automáticamente *"Allianz Seguros"* a *"Allianz"* sin duplicarlo.

### 3. Lógica de Auto-Reparación
Si tras la búsqueda el dato no existe, el script aplica reglas de negocio para evitar errores:
* **Sin Cliente:** Si el campo "Propietario" está vacío, usa el nombre del Proyecto como nombre de cliente.
* **Cliente Nuevo:** Si no existe, lo crea automáticamente ("al vuelo") y le asigna un ID.
* **Sitio Huérfano:** Si un proyecto apunta a una dirección que no existe en los archivos de Sitios, genera un "Sitio Fantasma" con el nombre del proyecto para no perder el vínculo.

---

## 📋 Estructura de Datos Esperada

Para que la importación funcione, los archivos CSV deben cumplir este formato:

### Archivo: `import_proyectos.csv`
| Índice | Columna Excel | Uso en Script |
| :--- | :--- | :--- |
| **[0]** | Nombre del proyecto | Referencia única (`ref`) |
| **[2]** | Nombre Marketing | Título y Vínculo con Sitio |
| **[7]** | Propietario | Nombre del Cliente (Tercero) |
| **[11]** | Ratecard | Dato Financiero |
| **[13]** | Producción | Dato Financiero |
| **[14]** | Forecasted Sales | Dato Financiero |
| **[15]** | Gross Profit | Dato Financiero |
| **[19]** | Estado | Borrador / Validado |
| **[23]** | Montaje | Fecha Inicio |
| **[24]** | Demontaje | Fecha Fin |

### Archivos: `sites_20XX.csv`
| Índice | Columna Excel | Uso en Script |
| :--- | :--- | :--- |
| **[0]** | Nº emplazamiento | Referencia Externa |
| **[3]** | Nombre Marketing | Nombre del Sitio (Clave de enlace) |
| **[7]** | Precio total | Tarifa |
| **[10-12]**| Medidas | Ancho / Alto / Superficie |
| **[19-20]**| GEO | Latitud / Longitud |

---

## 🚀 Instalación y Uso

### 1. Requisitos
* Servidor Web (Apache/Nginx) con PHP 7.4+.
* Acceso a la base de datos MySQL de Dolibarr.
* La base de datos debe tener la tabla personalizada `presupuestos_indicadores`.

### 2. Configuración
1.  Clona este repositorio en una carpeta pública de tu servidor (ej: `htdocs/migracion`).
2.  Edita el archivo `migracion_final_v7.php` y ajusta las credenciales:
    ```php
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = 'tu_contraseña';
    $db_name = 'dol_ikonik';
    ```
3.  Coloca tus archivos CSV (`sites_2015.csv`, `import_proyectos.csv`, etc.) en la **misma carpeta** que el script.

### 3. Ejecución
Abre tu navegador web y visita la URL del script:
> `http://localhost/migracion/migracion_final_v7.php`

El proceso mostrará una barra de progreso en tiempo real. **No cierres la pestaña** hasta que veas el mensaje "¡MISIÓN CUMPLIDA!".

---

## 🛠 Scripts de Utilidad

El repositorio incluye herramientas adicionales para mantenimiento:

* **`delete_registros_today.php` (Rollback):**
    * *Función:* Borra todos los datos creados **HOY**.
    * *Uso:* Ejecutar si la migración ha salido mal y quieres empezar de cero limpio.
    * *Seguridad:* Borra en orden inverso (Hijos -> Padres -> Clientes) para evitar errores SQL.

* **`check_clientes.php` (Diagnóstico):**
    * *Función:* Simula la importación sin escribir en la BD.
    * *Uso:* Muestra una tabla comparativa de qué clientes del Excel coinciden con la BD y cuáles se crearían como nuevos.

---

## ⚠️ Notas de Seguridad
* El archivo `.gitignore` está configurado para **bloquear la subida de CSVs y Excel** por defecto.
* Si necesitas subir datos para revisión, edita el `.gitignore` bajo tu responsabilidad.
* Se recomienda realizar un **Backup completo de la base de datos** antes de ejecutar el script en un entorno de producción.