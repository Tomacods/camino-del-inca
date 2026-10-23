# Pruebas de CU-24 Iniciar Sesión y CU-25 Cerrar Sesión

Revisión del PR #57 de Mariano, realizada el 23/10/2026 sobre el commit
`522de80b61d7e882fca2aa5ef3384476677df2e5`. El PR agrega nueve pruebas en
`tests/Feature/SesionTest.php`; el inicio y el cierre de sesión ya estaban implementados.

## Documentación y capturas

- [Informe de pruebas en Word](evidencias/cu24-cu25/Pruebas_CU24_CU25_PR57_Grupo11.docx): 24 páginas con pasos, resultados y 20 capturas reales.
- [Índice de capturas](evidencias/cu24-cu25/Indice_de_capturas.csv): caso, acción, resultado, horario y SHA-256 de cada PNG.
- [Capturas originales](evidencias/cu24-cu25/capturas): material para el manual de usuario, sin recortes ni anotaciones.
- [Registros de las comprobaciones](evidencias/cu24-cu25/evidencias%20tecnicas).

El informe contrasta los requisitos R-24 y R-25 de la ERS con CU-24 y CU-25 del Documento 3.

## Casos comprobados en el navegador

| Caso | Comprobación | Resultado |
|---|---|---|
| CP01 | Ingreso del administrador con correo y contraseña válidos | Conforme |
| CP02 | Ingreso del guía con correo y contraseña válidos | Conforme |
| CP03 | Contraseña incorrecta | Acceso rechazado y mensaje de credenciales |
| CP04 | Correo no registrado | Acceso rechazado con el mismo mensaje |
| CP05 | Campos obligatorios vacíos | El navegador impide continuar; SesionTest comprueba ambos campos en el servidor |
| CP06 | Acceso a Reservas y Mis excursiones sin sesión | Redirección al formulario de acceso |
| CP07 | Permisos del administrador | Accede a Reservas; recibe 403 en Mis excursiones |
| CP08 | Permisos del guía | Accede a Mis excursiones; recibe 403 en Reservas |
| CP09 | Cierre del administrador y reapertura de Reservas | Vuelve a pedir iniciar sesión |
| CP10 | Cierre del guía y reapertura de Mis excursiones | Vuelve a pedir iniciar sesión |

Se usó una copia aislada en Windows, Google Chrome y una base SQLite descartable con los
seeders del proyecto. Los recorridos se ejecutaron con Playwright. No se probó en PostgreSQL,
un servidor publicado, otros navegadores ni pantallas móviles. El informe describe las
cuentas de demostración y los límites de la evidencia.

## Comprobaciones automáticas

| Comprobación | Resultado final |
|---|---|
| Migraciones y seeders en la SQLite de capturas | Sin errores |
| SesionTest en SQLite en memoria | 9 pruebas y 48 aserciones aprobadas |
| Suite completa en SQLite en memoria | 607 pruebas y 2692 aserciones aprobadas |
| Pint en modo de comprobación | Aprobado |

Los resultados finales no tuvieron advertencias. Se descartó una ejecución inicial por un
error en la configuración del entorno de revisión; la incidencia y su corrección están
registradas en el informe. La base habitual del proyecto no se modificó.

## Estado y seguimiento

No se encontraron defectos bloqueantes en el cambio revisado. La aprobación y la integración
se registran en GitHub; subir esta documentación no cierra el PR.

La casilla de prueba manual del autor seguía sin marcar al realizar la revisión. Estos
recorridos de navegador no se presentan como una declaración de aceptación de Mariano.

Al integrarse Paquetes y Valoraciones, conviene ampliar la cobertura de permisos sobre esas
secciones. Es un seguimiento posterior, separado de los casos de acceso y cierre comprobados acá.
