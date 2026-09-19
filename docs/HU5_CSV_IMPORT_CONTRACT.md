# HU5 T5.2 — Contrato y Estrategia de Importación CSV

## 1. Formato oficial

El padrón institucional se importará mediante archivos CSV.

### Columnas obligatorias

- sis_code
- identity_number
- first_names
- last_names
- email
- career
- profile_photo

## 2. Delimitador

El delimitador oficial es:

`,`

## 3. Codificación

La codificación aceptada es:

UTF-8

## 4. Código SIS

El Código SIS se trata como texto.

Debe ser obligatorio y único.

## 5. CI

El CI se trata como texto.

Debe ser obligatorio y único.

## 6. Nombres y apellidos

Las columnas first_names y last_names son obligatorias.

Los nombres compuestos se conservan como un único valor.

## 7. Correo

El correo es obligatorio.

Debe tener formato válido y utilizar el dominio institucional permitido.

## 8. Carrera

La carrera se identifica mediante su nombre.

La carrera debe existir previamente en la tabla careers.

No se crean carreras automáticamente durante la importación.

## 9. Tamaño máximo

El tamaño máximo permitido es de 10 MB.

## 10. Archivo vacío

Un archivo sin registros de estudiantes será rechazado.

## 11. Duplicados

Se detectan duplicados:

- dentro del CSV;
- contra estudiantes existentes;
- contra usuarios existentes.

Se consideran identificadores de duplicidad:

- SIS;
- CI;
- correo.

## 12. Importación parcial

La importación permite procesar las filas válidas aunque existan filas con errores.

Las filas inválidas serán rechazadas individualmente y reportadas indicando su número de fila.

## 13. Persistencia

Cada estudiante será procesado mediante una transacción independiente.

Si una fila falla, sus cambios se revierten sin afectar las demás filas válidas.