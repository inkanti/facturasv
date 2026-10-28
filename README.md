# FacturaSV — Módulos 1 al 16

SaaS multiempresa de facturación electrónica de El Salvador. Incluye autenticación, catálogos MH, clientes, productos, emisión de DTE en modo simulación, representación gráfica PDF, envío del DTE por correo, dashboard con reportes, configuración de la empresa, contingencia, los seis tipos de DTE, un panel de superadministración, un perfil para que cada usuario cambie su propia contraseña, un módulo de contabilidad, un inventario básico con movimientos y kardex, y un modo de facturación interno por empresa para comprobantes no fiscales.

**Alcance de esta entrega:** módulos 1 al 16 completos. El módulo 5 emite Factura (01) y Comprobante de Crédito Fiscal (03) con borradores, correlativos, cálculo fiscal, validación contra esquemas oficiales, firma y transmisión simuladas, consulta, descarga JSON e invalidación. El módulo 6 agrega la representación gráfica PDF del DTE, el logo del emisor y clientes sin documento de identidad para la Factura. El módulo 7 agrega el envío automático del PDF y del JSON al correo del cliente al emitir, el dashboard con indicadores y los reportes con exportación CSV. El módulo 8 agrega la configuración de empresa, emisor, numeración, usuarios y correo, con historial de cambios. El módulo 9 agrega la emisión de DTE en contingencia, el evento de contingencia del esquema MH v4 y la transmisión diferida de los documentos pendientes. El módulo 10 agrega la Nota de Crédito (05), la Nota de Débito (06), la Factura de Exportación (11) y la Factura de Sujeto Excluido (14), con sus esquemas oficiales, cálculos, documento relacionado, datos de exportación y retención de renta. El módulo 11 agrega el superadministrador global, el registro público cerrado y la gestión central de empresas y usuarios. La gestión de usuarios de cada empresa queda reservada al superadministrador; el `ADMIN` de la empresa solo consulta la lista. El módulo 12 agrega **Mi perfil**, donde cada usuario cambia únicamente su propia contraseña. El módulo 13 agrega **Contabilidad**: catálogo de cuentas por empresa, asientos automáticos a partir de los DTE procesados, contra-asientos por invalidación, asientos manuales con partida doble, libro diario, libro mayor, balanza de comprobación, estado de resultados, balance general y exportación CSV. El módulo 14 agrega **Inventario básico**: existencias calculadas por producto, movimientos de entrada, salida y ajuste, kardex, descuento automático de las ventas procesadas y reposición automática al invalidarlas, sin compras ni costeo. El módulo 15 agrega **modo de facturación por empresa**: el superadministrador puede poner una empresa en modo `INTERNO` para emitir comprobantes internos no fiscales (serie `INT-000001`, sin código de generación, sello, JSON DTE, transmisión ni correo fiscal) que sí descuentan inventario y generan asiento contable, o mantenerla en `SIMULACION` con los seis DTE de prueba. El historial no se convierte al cambiar de modo y `PRODUCCION` sigue bloqueado. El PDF y el ticket se rediseñaron con el formato institucional del ejemplo (tarjetas azules, tabla gris y resumen a la derecha) y ahora incluyen el **código QR** de consulta pública del DTE. La extensión de ticket térmico de 58/80 mm se mantiene. El módulo 16 agrega el **código de barras por producto**: campo opcional único por empresa, búsqueda en el catálogo y escaneo con lector USB en la factura, que agrega la línea o incrementa la cantidad. La impresión de **ticket térmico de 58/80 mm** se suma a la representación PDF del DTE, con acceso desde la lista y el detalle de documentos.

## Requisitos

- Apache 2.4 o superior, con `mod_rewrite` y `.htaccess` habilitados.
- PHP 8.0 o superior con las extensiones `pdo_mysql`, `mbstring`, `session` y `gd`.
- MySQL 8.0 o superior. También es compatible con MariaDB 10.4 o superior.
- Un servidor SMTP para el envío real de correos (opcional: por omisión el envío es simulado).
- No requiere Composer, Node.js ni servicios externos. Bootstrap 5.3.3 y jQuery 3.7.1 están incluidos localmente. El PDF y el cliente SMTP se implementan en PHP, sin librerías externas.

`gd` se utiliza únicamente para validar y normalizar el logo de la empresa a JPEG; el resto del sistema funciona sin esa extensión.

## Estructura de archivos del módulo

```text
facturacion electronica/
├── .htaccess
├── index.php
├── README.md
├── api/                 # Autenticación, catálogos, clientes, productos, documentos, MH, reportes, configuración, contingencia, contabilidad e inventario
├── assets/              # Bootstrap, jQuery, estilos y JavaScript de cada pantalla
│   ├── img/             # Logo de BissWeb y su isotipo
│   └── vendor/          # Bootstrap y jQuery locales
├── config/config.php
├── config/correo.local.example.php   # Plantilla opcional de credenciales SMTP
├── database/
│   ├── migrations/      # Migraciones 002 a 017
│   ├── schema.sql
│   └── seed.sql
├── docs/manual/          # Manual de usuario en Markdown, DOCX y PDF, con capturas
├── documentacion-mh/    # Esquemas y documentos oficiales; acceso web denegado
├── svfe-json-schemas/   # Fuente de esquemas oficiales adicionales
├── lib/
│   ├── documentos.php
│   ├── dte-builder.php
│   ├── dte-builder-modulo10.php  # Cálculo y JSON de los tipos 05, 06, 11 y 14
│   ├── contingencia.php     # Emisión en contingencia y eventos MH v4 (módulo 9)
│   ├── superadmin.php       # Empresas y usuarios globales (módulo 11)
│   ├── perfil.php           # Datos de la cuenta y cambio de contraseña (módulo 12)
│   ├── contabilidad.php     # Catálogo, asientos, libros y estados financieros (módulo 13)
│   ├── inventario.php       # Existencias, movimientos y kardex (módulo 14)
│   ├── ticket.php           # Datos e impresión de tickets de 58/80 mm
│   ├── qr.php               # Generador QR propio (matriz, PNG y URL de consulta)
│   ├── superadmin.php       # Empresas, usuarios y modo de facturación (módulos 11 y 15)
│   ├── dte-pdf.php          # Representación gráfica del DTE (módulos 6 y 10)
│   ├── pdf-simple.php       # Escritor PDF propio, sin dependencias
│   ├── correo.php           # Mensaje MIME, cliente SMTP y registro de envíos (módulo 7)
│   ├── reportes.php         # Dashboard, reportes y exportación CSV (módulo 7)
│   ├── configuracion.php    # Empresa, emisor, numeración y usuarios (módulo 8)
│   ├── empresa.php          # Validación y almacenamiento del logo
│   ├── json-schema-validator.php
│   ├── mh-connector.php
│   ├── numero-letras.php
│   └── …                # Autenticación, BD, catálogos, clientes y productos
├── tests/               # Pruebas repetibles de los módulos 5 al 16, QR y tickets
├── uploads/logos/       # Logos normalizados a JPEG; scripts denegados por .htaccess
└── views/
    ├── nueva-factura.php
    ├── lista-documentos.php
    ├── ticket.php
    ├── contingencia.php
    ├── superadmin.php
    ├── perfil.php
    ├── contabilidad.php
    ├── inventario.php
    ├── dashboard.php
    ├── reportes.php
    ├── configuracion.php
    └── …                # Administración y parciales compartidos
```

## Acceso e interfaz

- El login permite entrar con **usuario o correo electrónico**; el identificador se valida en vivo y muestra un aviso si el correo no tiene un formato válido.
- Incluye botón para **mostrar u ocultar la contraseña**, aviso de **Bloq Mayús** y un enlace de ayuda con las instrucciones de restablecimiento.
- **Mantener sesión activa** extiende la cookie de sesión a 30 días; sin esa opción, la sesión termina al cerrar el navegador. La casilla viene desmarcada por seguridad y debe activarse solo en equipos de confianza. El servidor mantiene las sesiones con un tiempo de vida mayor para que la persistencia sea real.
- El acceso está protegido con CSRF, bloqueo temporal de intentos fallidos y regeneración de sesión.
- La pantalla de acceso y el menú lateral incluyen el logo de **BissWeb — Impulso Digital**, con un diseño adaptable a escritorio y móvil.
- **SSO corporativo, passkeys y magic links** no están implementados todavía: se muestran como hoja de ruta y requieren backend, proveedor de identidad o envío de enlaces por correo. No hay botones de proveedores simulados.

## Manual de usuario

El manual de usuario de los módulos 1 al 14 está disponible en tres formatos:

- [PDF para leer o imprimir](docs/manual/manual_usuario_facturasv_v2.pdf)
- [DOCX editable](docs/manual/manual_usuario_facturasv.docx)
- [Markdown fuente](docs/manual/manual_usuario_facturasv.md)

Incluye acceso y roles, clientes, productos, inventario, emisión de los seis DTE, ticket térmico de 58/80 mm, contingencia, reportes, contabilidad, configuración, superadministración, perfil, correo, seguridad, solución de problemas, glosario y anexos. El PDF tiene 30 páginas y capturas de las pantallas principales. Para regenerarlo después de un cambio visual, ejecute `docs/manual/build_manual.py` y exporte el DOCX con LibreOffice Kit. La fuente Markdown ya incluye la sección **9.6 Modo interno por empresa**; el DOCX y el PDF todavía corresponden a los módulos 1 al 14 y deben regenerarse.

## Contexto de traspaso

[contexto_actual.txt](contexto_actual.txt) resume el estado del sistema, las credenciales demo, los cambios recientes, la impresión de tickets, el modo interno ya implementado y los siguientes pasos. Úselo al continuar el proyecto en otra sesión.

## Instalación en LAMP

### 1. Copiar el proyecto

Copie la carpeta al servidor, por ejemplo en `/var/www/facturasv2`. El `DocumentRoot` debe apuntar a la raíz del proyecto, donde se encuentra `index.php`.

El usuario de Apache necesita permisos de lectura sobre todos los archivos y de escritura sobre `uploads/logos/`. Esa carpeta guarda el logo de cada empresa, normalizado a JPEG; su `.htaccess` impide ejecutar scripts subidos. El resto del proyecto es de solo lectura.

### 2. Crear la base e importar los datos

Acceda a MySQL con un usuario que pueda crear bases de datos:

```bash
mysql -u root -p < /var/www/facturasv2/database/schema.sql
```

Para un entorno local o de demostración, importe también el usuario demo:

```bash
mysql -u root -p < /var/www/facturasv2/database/seed.sql
```

Si usa phpMyAdmin, importe primero `database/schema.sql` y después `database/seed.sql`.

`schema.sql` crea la base `facturasv2` con codificación `utf8mb4`. `seed.sql` es idempotente: puede ejecutarse nuevamente sin duplicar el tenant, los usuarios demo, los catálogos, los clientes ni los productos; tampoco sobrescribe cambios realizados en registros existentes. La aplicación usa `facturasv2` de forma predeterminada y no necesita acceder a ninguna otra base.

Las instalaciones anteriores deben aplicar las migraciones pendientes en orden, desde `002_login_sin_nit.sql` hasta `017_codigo_barras.sql`, dentro de `database/migrations/`. Para el módulo 5 aplique `006_documentos.sql` y después `007_catalogos_oficiales.sql` sobre `facturasv2`; la primera crea documentos, ítems, registros MH y correlativos, y la segunda actualiza unidades y formas de pago oficiales. Para el módulo 6 aplique `008_clientes_sin_documento_logo.sql`, que permite clientes sin documento de identidad (consumidor final) y agrega `tenants.logo_path` para el logo del emisor. Para el módulo 7 aplique `009_correo_documentos.sql`, que crea el historial de envíos por correo. Para el módulo 8 aplique `010_configuracion.sql`, que agrega departamento y municipio configurables del emisor y la bitácora `configuracion_cambios`. Para el módulo 9 aplique `011_contingencia.sql`, que crea los eventos de contingencia, su detalle de documentos y la marca de contingencia en los DTE. Para el módulo 10 aplique `012_modulo10_tipos_dte.sql`, que agrega el documento relacionado de las notas, los campos de exportación y el importe de compra del sujeto excluido. Para el módulo 11 aplique `013_superadmin.sql`, que hace global la tabla `users`, agrega el rol `SUPERADMIN` y crea la cuenta inicial. Para el módulo 13 aplique `014_contabilidad.sql`, que crea el catálogo de cuentas, la configuración de cuentas automáticas, los asientos, las partidas y la secuencia contable por empresa; al abrir Contabilidad se cargan las cuentas base y los DTE procesados existentes se pueden contabilizar con “Generar asientos”. Para el módulo 14 aplique `015_inventario.sql`, que crea la configuración y el libro de movimientos de inventario; la existencia base de cada producto es el valor actual de `productos.stock`, y el stock se calcula sumando los movimientos posteriores. Para el módulo 15 aplique `016_modo_interno.sql`, que agrega `tenant_config.modo_facturacion` y crea la serie interna `IN` para las empresas existentes. Para el módulo 16 aplique `017_codigo_barras.sql`, que agrega `productos.codigo_barras` con unicidad por empresa y copia como código de barras los códigos internos numéricos de 8, 12, 13 o 14 dígitos. Después de las migraciones iniciales puede reejecutar `seed.sql` para cargar los datos demo sin duplicarlos. Las instalaciones nuevas ya incluyen todos los ajustes y no necesitan ejecutar las migraciones.

> En producción, omita `seed.sql` o cambie las credenciales demo. El registro público está deshabilitado: las empresas y sus usuarios se crean desde el panel de superadministración. No utilice `SuperDemo1234!` en un sistema público.

### 3. Crear un usuario MySQL para la aplicación

No se recomienda utilizar `root` en producción. Cree un usuario con permisos únicamente sobre FacturaSV:

```sql
CREATE USER 'facturasv2_app'@'localhost' IDENTIFIED BY 'CAMBIE_ESTA_CLAVE';
GRANT SELECT, INSERT, UPDATE, DELETE ON facturasv2.* TO 'facturasv2_app'@'localhost';
FLUSH PRIVILEGES;
```

La importación del esquema se realiza por separado con un usuario administrador; la aplicación solo necesita los permisos anteriores para operar.

### 4. Configurar Apache

Ejemplo de virtual host para un dominio local:

```apache
<VirtualHost *:80>
    ServerName facturasv2.local
    DocumentRoot /var/www/facturasv2

    <Directory /var/www/facturasv2>
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv APP_ENV production
    SetEnv APP_URL http://facturasv2.local
    SetEnv DB_HOST 127.0.0.1
    SetEnv DB_PORT 3306
    SetEnv DB_NAME facturasv2
    SetEnv DB_USER facturasv2_app
    SetEnv DB_PASS CAMBIE_ESTA_CLAVE

    # Correo del módulo 7. Sin MAIL_TRANSPORT el envío queda simulado.
    # SetEnv MAIL_TRANSPORT smtp
    # SetEnv MAIL_HOST smtp.midominio.com
    # SetEnv MAIL_PORT 587
    # SetEnv MAIL_ENCRYPTION tls
    # SetEnv MAIL_USER facturas@midominio.com
    # SetEnv MAIL_PASS CAMBIE_ESTA_CLAVE
    # SetEnv MAIL_FROM facturas@midominio.com
    # SetEnv MAIL_FROM_NAME "Mi empresa"

    ErrorLog ${APACHE_LOG_DIR}/facturasv2-error.log
    CustomLog ${APACHE_LOG_DIR}/facturasv2-access.log combined
</VirtualHost>
```

Habilite el sitio y `mod_rewrite`, luego reinicie Apache:

```bash
sudo a2enmod rewrite
sudo a2ensite facturasv2.conf
sudo apache2ctl configtest
sudo systemctl restart apache2
```

`APP_URL` no debe terminar en `/`. Si no se define, la aplicación intenta deducir la ruta base automáticamente; definirla es recomendable en producción.

En un alojamiento compartido, las mismas variables pueden configurarse desde el panel o mediante `SetEnv` en `.htaccess`, si el proveedor lo permite. No publique credenciales en un repositorio.

### 5. Configurar el envío de correo

El módulo 7 envía el PDF y el JSON del DTE al correo del cliente al emitir. Los valores se definen con variables de entorno, nunca en la base de datos:

| Variable | Ejemplo | Uso |
|---|---|---|
| `MAIL_TRANSPORT` | `smtp` | `smtp`, `mail` o `simulado` (predeterminado). |
| `MAIL_HOST` | `smtp.midominio.com` | Servidor SMTP. |
| `MAIL_PORT` | `587` | 587 para TLS, 465 para SSL, 25 para sin cifrado. |
| `MAIL_ENCRYPTION` | `tls` | `tls` (STARTTLS), `ssl` o `none`. |
| `MAIL_USER` | `facturas@midominio.com` | Usuario de autenticación. |
| `MAIL_PASS` | `CAMBIE_ESTA_CLAVE` | Contraseña o clave de aplicación. |
| `MAIL_FROM` | `facturas@midominio.com` | Remitente; suele exigirse que coincida con el usuario. |
| `MAIL_FROM_NAME` | `Mi empresa` | Nombre visible del remitente. |
| `MAIL_REPLY_TO` | `cobros@midominio.com` | Opcional; por omisión responde al correo de la empresa. |

Con `MAIL_TRANSPORT=simulado` (o sin configurar) el sistema genera el mensaje, adjunta PDF y JSON y lo registra en el historial del documento, pero no contacta ningún servidor. Es el modo útil para desarrollo. Para probar el envío real, use un buzón o servicio de pruebas SMTP antes de apuntar a los clientes.

En alojamientos con `mail()` disponible puede usar `MAIL_TRANSPORT=mail`; el envío por SMTP autenticado es más confiable y es el recomendado en producción. Si el servidor exige TLS, verifique que PHP tenga `openssl` habilitado.

Si no puede definir variables de entorno en Apache, copie `config/correo.local.example.php` como `config/correo.local.php` y complete los mismos valores. Ese archivo queda dentro de `config/`, que está bloqueado desde el navegador, y está incluido en `.gitignore`: no lo versione ni comparta. Por seguridad, el repositorio no incluye credenciales SMTP; sin configuración el transporte queda en **simulado**.

#### Ejemplo con Mailtrap

Mailtrap separa las credenciales de la cuenta de las de envío:

- **Sandbox (pruebas):** en Mailtrap abra **Sandboxes → su bandeja → Integration → SMTP** y copie host, puerto, usuario y contraseña. Use `sandbox.smtp.mailtrap.io`, puerto `2525` o `587`, cifrado `tls`. Los correos **no llegan al cliente**: quedan en la bandeja de Mailtrap para revisión. El plan de pruebas limita la cantidad de correos por segundo; si el servidor responde `550 Too many emails per second`, espere unos segundos y reintente.
- **Envío real:** requiere un dominio propio verificado en **Sending Domains** (registros SPF, DKIM y DMARC) y un token en **API Tokens**. Use `live.smtp.mailtrap.io`, puerto `587`, cifrado `tls`, usuario `api` y como contraseña el token. El remitente debe pertenecer al dominio verificado; Mailtrap no permite enviar en nombre de dominios ajenos.

La contraseña de la cuenta de Mailtrap no funciona como contraseña SMTP: las credenciales de envío se generan aparte.

#### Ejemplo con Gmail

Use `smtp.gmail.com`, puerto `587`, cifrado `tls`, el correo completo como usuario y una **contraseña de aplicación** de Google (requiere verificación en dos pasos activa). Sirve para pruebas o volúmenes pequeños; para facturación conviene un dominio propio y un proveedor transaccional.

### 6. Configurar HTTPS

En un sitio público, instale un certificado TLS y utilice HTTPS. La cookie de sesión se marca automáticamente como `Secure` cuando la petición llega por HTTPS. La cookie también usa `HttpOnly` y `SameSite=Lax`.

### 7. Abrir la aplicación

Visite la URL configurada. Para el proyecto incluido en XAMPP, la URL local es:

```text
http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/
```

## Credenciales demo

| Campo | Valor |
|---|---|
| NIT | `0614-000000-000-0` |
| Usuario | `admin` |
| Contraseña | `Demo1234!` |
| Rol | `ADMIN` |
| Modo MH | `SIMULACION` |

Superadministrador global:

| Campo | Valor |
|---|---|
| Usuario | `superadmin` |
| Contraseña | `SuperDemo1234!` |
| Rol | `SUPERADMIN` |
| Empresa | Ninguna (`tenant_id` nulo) |

> Cambie ambas contraseñas en producción. El superadministrador no debe compartir credenciales con los administradores de empresa.

## Cómo probar el módulo

### Acceso y persistencia

1. Abra la URL de la aplicación; debe redirigir a **Iniciar sesión**.
2. Intente entrar con una contraseña incorrecta: debe aparecer un mensaje genérico sin revelar qué dato falló.
3. Ingrese con las credenciales demo.
4. Debe aparecer el dashboard con **Empresa Demo**, el NIT, el rol `ADMIN`, la zona horaria y `SIMULACION`.
5. Cierre y vuelva a abrir el navegador. La empresa y el usuario deben seguir almacenados en MySQL.
6. Pulse **Cerrar sesión**. Al intentar abrir directamente `views/dashboard.php`, debe regresar al acceso.
7. Para el panel global, cierre sesión e ingrese con `superadmin` / `SuperDemo1234!`. Debe abrir **Superadministración**, no el dashboard de una empresa.

### Superadministración de empresas y usuarios

1. Inicie sesión con `superadmin` / `SuperDemo1234!` y abra **Superadministración**.
2. Pulse **Nueva empresa**, complete los datos fiscales y el primer administrador. La empresa nace con sus catálogos y sus seis series.
3. En la fila de la empresa, pulse **Usuarios** para ver sus usuarios y **Nuevo usuario** para asignar un `ADMIN`, `VENDEDOR` o `CONTADOR`. Una empresa puede tener uno o muchos usuarios.
4. Use **Contraseña** para restablecer la clave de un usuario y **Desactivar/Activar** para bloquear o habilitar su acceso.
5. Use **Eliminar** en una empresa para desactivarla: los datos fiscales se conservan, sus usuarios no pueden iniciar sesión y la empresa puede reactivarse con **Reactivar**.
6. Inicie sesión como `admin`, abra **Configuración → Usuarios**: debe ver la lista en modo **Solo lectura**, sin botones para crear, editar, activar o cambiar contraseñas. La API `api/configuracion.php` debe rechazar `user-create`, `user-update` y `user-password` con `403`.
7. Compruebe que un administrador de empresa no puede abrir `views/superadmin.php` ni llamar a `api/superadmin.php` (HTTP `403`/`401`).
8. Abra `views/registro.php`: debe redirigir al login con el mensaje de que el registro público está deshabilitado. El endpoint `api/auth.php?action=register` responde `403`.

### Mi perfil

1. Inicie sesión como `admin` y abra **Mi perfil** en el menú lateral.
2. Debe ver su nombre, usuario, correo, rol, empresa y último acceso, sin opciones para editar esos datos.
3. En **Cambiar contraseña**, indique la contraseña actual, la nueva y su confirmación. La nueva debe tener al menos 10 caracteres, mayúsculas, minúsculas y números, y ser diferente a la actual.
4. Pruebe una contraseña actual incorrecta o una nueva débil: la operación debe rechazarse con errores junto al campo.
5. Cambie la contraseña y cierre sesión. La contraseña anterior ya no debe funcionar; la nueva sí.
6. El superadministrador también tiene **Mi perfil** desde el panel global y puede cambiar su propia contraseña con el mismo formulario.

### Validaciones y seguridad

- El registro público está cerrado; solo el superadministrador crea empresas y asigna usuarios.
- La gestión de usuarios está reservada al superadministrador. El `ADMIN` de una empresa solo puede consultar la lista en **Configuración → Usuarios**; la API rechaza altas, ediciones, activaciones y cambios de contraseña con `403`.
- **Mi perfil** permite a cada usuario cambiar únicamente su propia contraseña: exige la contraseña actual, valida la fortaleza, rechaza reutilizarla, usa CSRF y registra el cambio en la auditoría de la empresa. No permite editar nombre, usuario, correo, rol ni empresa.
- El superadministrador es un usuario global (`tenant_id` nulo) y no pertenece a ninguna empresa.
- Envíe altas de empresa con correos inválidos, contraseñas diferentes, una contraseña débil o formatos incompletos de NIT y teléfono: los errores deben aparecer junto a los campos.
- Después de cinco intentos fallidos para el mismo usuario, el acceso se bloquea temporalmente durante 15 minutos. Un acceso correcto elimina los intentos fallidos de esa cuenta.
- Modifique o elimine el token CSRF de un formulario antes de enviarlo: la API debe rechazar la petición con HTTP `403`.
- Compruebe que `config/`, `lib/`, `database/` y los archivos `.sql` no se puedan descargar directamente desde Apache.

### Catálogos MH

1. Inicie sesión como `ADMIN` y abra **Catálogos** en el menú lateral.
2. Recorra los ocho catálogos. Debe encontrar 6 tipos de documento, 14 departamentos y 44 municipios, entre los demás valores iniciales.
3. Busque por código o nombre con una o varias palabras y pruebe los filtros de estado y departamento.
4. Agregue un registro con un código nuevo, edítelo, desactívelo y elimine un registro de prueba que no tenga relaciones.
5. Intente eliminar un departamento con municipios: el sistema debe rechazarlo y ofrecer desactivarlo.
6. Con un usuario `VENDEDOR` o `CONTADOR`, la pantalla debe mostrar **Solo lectura**, sin botones de edición.
7. Registre otra empresa: sus catálogos deben inicializarse automáticamente y sus cambios no deben alterar los de Empresa Demo.

### Clientes

1. Inicie sesión como `ADMIN` y abra **Clientes**. Deben aparecer los tres clientes demo.
2. Cree un cliente con NIT y otro con DUI; compruebe las máscaras `0000-000000-000-0` y `00000000-0`. También puede elegir **Sin documento (consumidor final)**: el campo del número se deshabilita y varios clientes pueden compartir esa condición. Ese tipo solo es válido para Factura, no para Crédito Fiscal.
3. Al cambiar el departamento, el municipio debe actualizarse para mostrar únicamente opciones relacionadas.
4. Edite y desactive un cliente, busque por documento (con o sin guiones), nombre en cualquier orden o correo y pruebe los filtros de contribuyente y estado.
5. Use **Exportar CSV** y confirme que el archivo contiene los clientes filtrados, con encabezados en español técnico y valores separados por punto y coma.
6. Descargue la **plantilla CSV**, complete una o más filas e impórtela. Los códigos de departamento y municipio deben corresponder a los catálogos de la empresa.
7. Importe un archivo con una fila inválida: la operación debe rechazarse completa y mostrar el número de fila, sin insertar registros parciales.
8. Con `VENDEDOR`, compruebe que puede crear, editar e importar, pero no eliminar. Con `CONTADOR`, compruebe que solo puede consultar y exportar.

La importación admite hasta 1000 clientes y 2 MB. Las columnas requeridas son: `tipo_documento`, `numero_documento`, `nombre`, `nombre_comercial`, `actividad_economica`, `direccion`, `departamento_codigo`, `municipio_codigo`, `telefono`, `email`, `tipo_contribuyente` y `activo`.

### Productos y servicios

1. Inicie sesión como `ADMIN` y abra **Productos**. Deben aparecer cinco registros demo.
2. Cree un bien y un servicio con códigos diferentes. El código admite hasta 30 letras, números, puntos, guiones o guiones bajos y es único dentro de cada empresa.
3. Seleccione una unidad del catálogo MH, un precio con hasta 6 decimales y el tratamiento **Gravado (13 %)**, **Exento** o **No sujeto**.
4. Active **Controlar existencias** e ingrese el stock actual con hasta 4 decimales. Si lo deja desactivado, el stock queda sin controlar y no se almacena una cantidad. Al editar un producto, el control y la existencia base ya no se modifican: los cambios se registran desde **Inventario → Movimientos**.
5. Edite un registro, cambie únicamente su estado y guarde. Después pruebe la búsqueda por varias palabras y los filtros por tipo, IVA y estado.
6. Intente repetir un código en la misma empresa: la operación debe rechazarse. El mismo código sí puede utilizarse en otra empresa.
7. Con `VENDEDOR`, compruebe que puede crear y editar, pero no eliminar. Con `CONTADOR`, la pantalla y la API deben permitir solo consulta. Solo `ADMIN` puede eliminar.
8. Registre otra empresa y compruebe que sus productos, unidades y cambios permanecen separados de Empresa Demo.
9. En **Código de barras** puede escanear o escribir el código del producto (hasta 64 caracteres, sin espacios). Es opcional y solo puede pertenecer a un producto de la empresa.
10. Busque en el catálogo por código de barras: el campo **Buscar** acepta código interno, código de barras o descripción.

### Escaneo de código de barras en la factura

1. Conecte la pistola USB. No requiere controladores: funciona como teclado y envía el código seguido de **Enter**.
2. En **Nuevo documento**, el cursor queda en **Escanear código de barras**. Escanee un producto: la línea se agrega con cantidad 1 y el precio del catálogo.
3. Si el producto ya está en la factura, el escaneo suma 1 a la cantidad en lugar de duplicar la línea.
4. Si el código no existe o el producto está inactivo, aparece un aviso y no se agrega nada. El campo queda listo para el siguiente escaneo.
5. También puede escribir el código a mano y presionar **Enter** o **Agregar**.
6. La búsqueda usa el código exacto, sin distinguir mayúsculas de minúsculas. Un producto puede no tener código de barras y seguir vendiéndose desde el selector.

### Búsqueda inteligente

Los buscadores de **Clientes**, **Productos** y **Catálogos** comparten el mismo comportamiento:

- Se puede escribir una o varias palabras en cualquier orden: cada palabra debe aparecer en alguno de los campos del registro.
- No distingue mayúsculas ni acentos (`maria` encuentra `María`, `inalambrico` encuentra `inalámbrico`).
- En clientes también busca por documento y teléfono **sin guiones**, además de nombre, nombre comercial, correo y actividad.
- En productos busca por código interno, código de barras o descripción.
- En catálogos busca por código y nombre; en municipios incluye el nombre del departamento.
- Los resultados se actualizan mientras escribe (con una pausa breve) y el botón **Buscar** o **Enter** fuerza la búsqueda inmediata.
- Se ignoran los comodines `%` y `_` escritos por el usuario, para que se busquen como texto literal.

### Emisión de documentos DTE

1. Abra **Nuevo documento** y seleccione **Factura** o **Comprobante de Crédito Fiscal**. Los tipos 05, 06, 11 y 14 permanecen pendientes hasta incorporar sus esquemas oficiales.
2. Seleccione cliente, fecha, condición y forma de pago. Para CCF el cliente debe tener NIT y correo; para crédito debe indicar los días de plazo.
3. Agregue una o más líneas. El selector de producto es un **buscador**: escriba código, descripción o código de barras y elija el resultado; también puede navegar con las flechas y Enter. En Factura el precio incluye IVA; en CCF no lo incluye. Puede ajustar precio, cantidad y descuento por línea y aplicar IVA retenido del 1 %. También puede escanear el **código de barras** en la barra superior de la sección.
4. Use **Guardar borrador** para conservar y editar sin consumir correlativo. Use **Emitir y transmitir** para generar el número de control, UUID, JSON validado, firma y sello simulados.
5. Abra **Documentos** para filtrar, consultar el detalle y el historial, reintentar transmisiones, descargar JSON o eliminar borradores.
6. Como `ADMIN`, puede invalidar un documento procesado indicando tipo, motivo y documento del responsable. La invalidación conserva el DTE y agrega su evento y respuesta al historial.
7. `VENDEDOR` puede crear y transmitir; `CONTADOR` solo puede consultar y descargar. Todas las consultas y acciones están aisladas por empresa.

El número de control sigue el formato `DTE-01-M001P001-000000000000001`, con correlativo independiente por empresa, tipo y establecimiento. El entorno actual es **SIMULACION**: las firmas, los sellos y las respuestas no tienen validez fiscal ni se envían al Ministerio de Hacienda.

### Contingencia

1. En **Nuevo documento**, marque **Emitir en contingencia**, seleccione el tipo (1 a 5) y, para el tipo 5, escriba el motivo. El documento se emite con `tipoOperacion=2`, sin sello ni transmisión; el PDF lo marca como **EMITIDO EN CONTINGENCIA**.
2. Abra **Contingencia** en el menú lateral. Como `ADMIN`, pulse **Nuevo evento** y ajuste el periodo real de la contingencia (fechas y horas), el tipo, el motivo y los datos del responsable.
3. Pulse **Actualizar** para consultar los DTE pendientes del periodo. El sistema solo incluye documentos emitidos en contingencia, sin evento, con fecha dentro del rango y del tipo de contingencia seleccionado.
4. El evento admite hasta 1000 DTE. Si hay más, divida la contingencia en varios eventos.
5. Al confirmar, el sistema valida el JSON contra `contingencia-schema-v4.json`, obtiene un sello simulado y transmite los DTE pendientes; cada uno recibe su propio sello y pasa a **Procesada**. El correo automático se envía en ese momento; antes puede descargar el PDF de contingencia para entregarlo al cliente.
6. Consulte el detalle del evento para ver el JSON v4, los documentos, sus estados, el sello y el historial. Puede descargar el JSON del evento.
7. Si el evento se rechaza, use **Reintentar evento**. Si el evento se procesó pero algún DTE falló, use **Reintentar documentos**.
8. `ADMIN` declara eventos; `ADMIN` y `VENDEDOR` emiten en contingencia; `CONTADOR` puede consultar. Todo está aislado por empresa.

### Notas, exportación y sujeto excluido

1. En **Nuevo documento** seleccione el tipo: Nota de Crédito (05), Nota de Débito (06), Factura de Exportación (11) o Factura de Sujeto Excluido (14).
2. Para las notas 05/06 seleccione primero el cliente y luego el **documento relacionado**. Solo aparecen Facturas y Créditos Fiscales procesados del mismo cliente. Si el relacionado es Factura 01, los precios incluyen IVA; si es Crédito Fiscal 03, no lo incluyen.
3. Para la exportación (11) complete código y nombre del país, tipo de persona, tipo de exportación, complemento de dirección, incoterm, flete, seguro y muestras no onerosas. El total suma flete y seguro y no lleva IVA.
4. Para el sujeto excluido (14) indique la **retención de renta**; el documento no lleva IVA y el total es la compra menos la retención.
5. Todos los tipos usan los mismos correlativos, borradores, PDF, correo, transmisión simulada, invalidación y contingencia. Los reportes restan las notas de crédito (05) y suman las notas de débito (06).

### Representación gráfica PDF

1. Abra **Documentos**, pulse **Detalle** en un documento **Procesada** o **Invalidada** y use **Ver PDF** (se abre en una pestaña nueva para imprimir o guardar) o **Descargar PDF** (archivo adjunto). El nombre del archivo es el número de control.
2. El PDF es tamaño carta y sigue el formato institucional: tarjetas con borde azul y esquinas redondeadas, encabezado con logo, tipo de documento y **código QR**, datos de identificación en dos columnas (código de generación, número de control, sello, modelo, tipo de transmisión y fecha/hora), emisor y receptor en dos tarjetas y, en las notas, la tarjeta del documento relacionado.
3. El QR codifica la URL de consulta pública `https://admin.factura.gob.sv/consultaPublica?ambiente=00|codGen=...&fechaEmi=...` (ambiente `00` en simulación y `01` en producción). Se genera en PHP puro, sin librerías externas, y también se incluye en el ticket.
4. El cuerpo usa una tabla con encabezado gris: N°, cantidad, unidad, descripción, precio unitario, descuento, otros montos, ventas no sujetas, exentas y gravadas. Para exportación y sujeto excluido cambian las dos últimas columnas según el tipo.
5. Debajo de la tabla, el resumen alineado a la derecha muestra suma de ventas por tipo, descuentos, sub-total, IVA, IVA retenido, renta, monto total de la operación y total a pagar. Después aparecen el valor en letras, la condición, la forma de pago y las observaciones.
6. Verifique un documento con muchas líneas: la tabla continúa en otra página con un encabezado compacto, el pie numera **Página X de Y** y el resumen queda en la última página.
7. Abra un documento invalidado: conserva su PDF con la etiqueta **DOCUMENTO INVALIDADO**, la marca de agua y el motivo registrado. Un documento en contingencia muestra **EMITIDO EN CONTINGENCIA**.
8. Compruebe los roles: `ADMIN`, `VENDEDOR` y `CONTADOR` pueden ver y descargar el PDF de los documentos de su empresa. Un borrador no genera PDF hasta emitirse, y otra empresa recibe `404`.
9. El logo es opcional. Al registrar una empresa se acepta PNG o JPG de hasta 1 MB y entre 40×40 y 2000×2000 px; se normaliza a JPEG y se muestra en el PDF. Sin logo se dibuja un recuadro con la leyenda **LOGO**.
10. Para **Factura**, el cliente puede registrarse sin DUI ni NIT (consumidor final) y el PDF indica **Sin documento**; el **Comprobante de Crédito Fiscal** sigue exigiendo NIT y correo.
11. Un comprobante interno no tiene QR fiscal: el encabezado muestra **COMPROBANTE INTERNO / DOCUMENTO NO FISCAL** y un recuadro sin QR.

La representación gráfica es una vista de solo lectura del DTE emitido: no modifica el JSON, el sello ni los totales ya validados, y puede generarse tantas veces como se necesite.

### Impresión de ticket térmico

En **Documentos**, los DTE **Procesada** o **Invalidada** muestran el botón **Ticket** en la lista y **Imprimir ticket** en el detalle. La vista [views/ticket.php](views/ticket.php) permite elegir **80 mm** o **58 mm**, muestra empresa, cliente, ítems, totales, IVA, retención, forma de pago, código de generación, sello y el **código QR** de consulta (80 mm: 36 mm de ancho; 58 mm: 28 mm), y abre el diálogo de impresión del navegador con `@page` del ancho elegido. Los documentos invalidados llevan la marca **DOCUMENTO INVALIDADO**; los emitidos en contingencia muestran **EMITIDO EN CONTINGENCIA**; los comprobantes internos muestran **COMPROBANTE INTERNO · NO FISCAL** y no incluyen QR fiscal. El ticket es una representación adicional y no sustituye el PDF oficial ni tiene validez fiscal en modo simulación.

### Modo de facturación por empresa (interno / simulación)

Cada empresa tiene un **modo de facturación** en `tenant_config.modo_facturacion`. Solo el **superadministrador** puede cambiarlo, desde la columna **Modo** de la lista de empresas o al crear una empresa nueva. `SIMULACION` es el valor por omisión y mantiene el comportamiento histórico con los seis tipos de DTE. `PRODUCCION` sigue bloqueado.

1. Cambie una empresa a **Interno** y confirme. La empresa deja de emitir DTE y pasa a emitir **comprobantes internos** (tipo `IN`).
2. Inicie sesión como `ADMIN` o `VENDEDOR`: **Nuevo documento** muestra solo el tipo **Comprobante interno (IN)**, oculta la contingencia y advierte que el documento no tiene validez fiscal.
3. Emita el comprobante. Queda **Procesada** sin código de generación, sin sello, sin JSON DTE y sin transmisión al MH. Usa una serie propia (`INT-000001`, prefijo configurable en **Numeración**) y conserva cliente, ítems, totales, IVA informativo, descuentos y observaciones.
4. El comprobante **descuenta inventario** (productos con control de existencias) y **genera el asiento de venta** en Contabilidad, igual que una venta fiscal, para que el control interno cuadre.
5. **PDF y ticket** se generan con la etiqueta **COMPROBANTE INTERNO** / **DOCUMENTO NO FISCAL**. No muestran código de generación ni sello, y el PDF no se presenta como DTE.
6. En el detalle no aparecen las acciones de **Descargar JSON**, **Consultar estado**, **Transmitir** ni **Enviar por correo**; la API rechaza esas acciones con un mensaje claro.
7. Un `ADMIN` puede **Anular comprobante** con un motivo. La anulación revierte el inventario, genera el contra-asiento contable y conserva el documento como **Invalidada**; no envía ningún evento al MH.
8. Al volver la empresa a **Simulación**, los comprobantes internos anteriores **se conservan** como historial y no se convierten en DTE. Los nuevos documentos vuelven a ser DTE de prueba.

Un comprobante interno **no es una factura** ni sustituye las obligaciones tributarias de la empresa. El modo mixto dentro de una misma empresa no está implementado.

### Dashboard y reportes

1. Al iniciar sesión, el **Dashboard** muestra los ingresos del mes, los documentos emitidos, el IVA generado y los documentos pendientes, con la comparación frente al mes anterior.
2. Revise el gráfico de ventas de los últimos seis meses y la lista de los cinco clientes con mayor venta del mes. Los documentos recientes enlazan al detalle en **Documentos**.
3. Abra **Reportes** en el menú lateral. Por omisión consulta el mes en curso; ajuste **Desde** y **Hasta** y pulse **Aplicar filtros** (o **Mes actual**).
4. Compruebe las cifras del resumen: ventas gravadas, exentas, no sujetas, descuentos, IVA débito, IVA retenido, total vendido, documentos pendientes e invalidados del periodo.
5. Revise las tablas de ventas por cliente, ventas por producto y servicio, resumen mensual de IVA y ventas por día.
6. Exporte cada tabla con **Exportar CSV**: el archivo descargado usa punto y coma, incluye BOM UTF-8 para Excel y contiene solo el rango filtrado.
7. Los totales consideran únicamente documentos **Procesada**; los borradores, emitidos sin procesar, rechazados e invalidados se informan aparte y no suman a las ventas. `ADMIN`, `VENDEDOR` y `CONTADOR` pueden consultar y exportar reportes.

### Envío por correo

1. Al emitir y transmitir un documento, el sistema envía automáticamente al correo del cliente el PDF y el JSON del DTE. Los datos del envío quedan en el detalle del documento, en **Envío por correo**.
2. Abra **Documentos → Detalle** de un documento procesado y use **Enviar por correo** o **Reenviar por correo**. El historial muestra fecha, destinatario, transporte (smtp, mail o simulado), estado y el error cuando falla.
3. Si el cliente no tiene correo, el intento se registra como **Omitido** y la emisión continúa normalmente.
4. Sin `MAIL_TRANSPORT` configurado, el correo queda en estado **Simulado**: se prepara y registra con sus adjuntos, pero no se envía. Configure las variables `MAIL_*` para el envío real.
5. Solo `ADMIN` y `VENDEDOR` pueden enviar o reenviar; `CONTADOR` puede consultar el historial pero no enviar. Cada envío está aislado por empresa.
6. Un fallo del servidor de correo no revierte el DTE: el documento queda procesado, el error se registra y puede reintentarse desde el detalle.

### Configuración de la empresa

1. Inicie sesión como `ADMIN` y abra **Configuración** en el menú lateral. La pantalla solo está disponible para administradores.
2. En **Empresa** revise y actualice razón social, nombre comercial, NIT, NRC, actividad económica, dirección, teléfono y correo. Suba un logo PNG/JPG o quítelo. Los cambios aplican a los próximos documentos; los DTE emitidos no se modifican.
3. En **Emisor y DTE** ajuste establecimiento y punto de venta (8 caracteres), distrito, departamento y municipio del emisor, y zona horaria. El entorno permanece en **SIMULACIÓN** mientras no exista conexión real al Ministerio de Hacienda. El **modo de facturación** se muestra como dato informativo: solo el superadministrador puede cambiarlo. Estos datos se usan en el bloque `emisor` del JSON y en el PDF.
4. En **Numeración** revise las series por tipo de documento. Puede cambiar el prefijo y el número actual; el sistema impide bajarlo por debajo del último número de control emitido. El próximo número será el actual más uno.
5. En **Usuarios** cree usuarios con rol `ADMIN`, `VENDEDOR` o `CONTADOR`, edite sus datos, actívelos o desactívelos y restablezca su contraseña. Siempre debe quedar al menos un administrador activo y nadie puede quitarse su propio rol de administrador.
6. En **Correo** consulte el transporte configurado y envíe un correo de prueba. Las credenciales SMTP se administran en el servidor (`config/correo.local.php` o variables `MAIL_*`), no en la base de datos.
7. En **Historial** revise los últimos cambios de configuración con su usuario y fecha.

### Consulta de verificación en MySQL

```sql
USE facturasv2;

SELECT t.nit, t.razon_social, t.logo_path, u.usuario, u.rol, c.mh_mode
FROM tenants t
INNER JOIN users u ON u.tenant_id = t.id
INNER JOIN tenant_config c ON c.tenant_id = t.id;

SELECT tipo_dte, establecimiento, prefijo, numero_actual
FROM document_sequences WHERE tenant_id = 1;

SELECT distrito_emisor, departamento_emisor, municipio_emisor, establecimiento, timezone, mh_mode
FROM tenant_config WHERE tenant_id = 1;

SELECT MAX(version) AS version_esquema FROM schema_versions;

SELECT documento_id, destinatario, transporte, estado, error, created_at
FROM documento_correos WHERE tenant_id = 1 ORDER BY id DESC LIMIT 10;

SELECT seccion, detalle, created_at
FROM configuracion_cambios WHERE tenant_id = 1 ORDER BY id DESC LIMIT 10;
```

### Pruebas automatizadas

Con Apache y MySQL activos, ejecute desde la raíz:

```bash
php tests/module5-smoke.php
php tests/module5-http.php
php tests/module6-pdf.php
php tests/module6-http.php
php tests/module7-smoke.php
php tests/module7-smtp.php
php tests/module7-http.php
php tests/module8-smoke.php
php tests/module8-http.php
php tests/module9-smoke.php
php tests/module9-http.php
php tests/module10-smoke.php
php tests/module10-http.php
php tests/module11-smoke.php
php tests/module11-http.php
php tests/module12-smoke.php
php tests/module12-http.php
php tests/module13-smoke.php
php tests/module13-http.php
php tests/module14-smoke.php
php tests/module14-http.php
php tests/modulo15-smoke.php
php tests/modulo15-http.php
php tests/barcode-smoke.php
php tests/barcode-http.php
php tests/smart-search-smoke.php
php tests/smart-search-http.php
php tests/qr-smoke.php
php tests/ticket-smoke.php
php tests/ticket-http.php
```

Las dos primeras cubren el módulo 5: la de consola valida cálculos, esquemas, estados, correlativos, transmisión e invalidación con datos temporales; la HTTP requiere un documento procesado y comprueba autenticación, CSRF, roles, descarga y aislamiento entre empresas. Las dos siguientes cubren el módulo 6: la de consola genera PDF de Factura, Crédito Fiscal, documento invalidado y documento de 45 líneas, comprueba el logo (normalización, rechazo de archivos que no son imagen), el QR incrustado y el aislamiento; la HTTP verifica autenticación, tipo de contenido, longitud, presentación en línea, descarga adjunta, QR, borradores sin PDF y acceso por rol y empresa.

Las tres siguientes cubren el módulo 7: `module7-smoke.php` comprueba dashboard, reportes, exportación, envío simulado, reenvío, cliente sin correo, error SMTP y el mensaje MIME con adjuntos; `module7-smtp.php` levanta un servidor SMTP local de un solo uso y valida el cliente SMTP completo (EHLO, AUTH LOGIN, MAIL, RCPT, DATA y adjunto); `module7-http.php` verifica dashboard y reportes por HTTP, exportación CSV, permisos de `CONTADOR`, historial de correos y aislamiento entre empresas.

Las dos anteriores cubren el módulo 8: `module8-smoke.php` valida datos de empresa, emisor (incluido el JSON del DTE), numeración, funciones internas de usuarios, protección del último administrador, auditoría y prueba de correo simulada; `module8-http.php` comprueba permisos de administrador, CSRF, guardado de empresa, emisor y series, consulta de usuarios en solo lectura, bloqueo de la gestión de usuarios, validación de correo y aislamiento entre empresas.

Las dos siguientes cubren el módulo 9: `module9-smoke.php` valida la emisión en contingencia, la validación del evento contra el esquema v4, el agrupamiento de DTE, el sello del evento, la transmisión diferida y la limpieza de datos; `module9-http.php` comprueba la pantalla, la emisión por HTTP, el evento, el PDF con la marca de contingencia, la descarga JSON, los permisos de `CONTADOR` y el aislamiento entre empresas.

Las dos anteriores cubren el módulo 10: `module10-smoke.php` emite una Factura base y los cuatro tipos nuevos, valida los esquemas 05/06/11/14, el documento relacionado, el flete/seguro, la compra y la retención de renta, y genera sus PDF; `module10-http.php` comprueba la pantalla, las secciones de documento relacionado/exportación/sujeto excluido, el guardado y la emisión por HTTP, el PDF y los permisos de `CONTADOR`.

Las dos siguientes cubren el módulo 11: `module11-smoke.php` crea una empresa y varios usuarios desde el superadministrador, comprueba series, catálogos, configuración contable y de inventario, desactiva/reactiva usuarios y empresas y verifica que una empresa inactiva no permita iniciar sesión; `module11-http.php` comprueba el login global por usuario y por correo, la opción de sesión persistente, el panel, la creación de empresa y usuarios, el restablecimiento de contraseña, la desactivación/reactivación, el registro público cerrado y que un administrador de empresa no acceda al panel global.

Las dos del módulo 12: `module12-smoke.php` valida el cambio de la propia contraseña, el rechazo de la contraseña actual incorrecta, la fortaleza, la reutilización y la auditoría; `module12-http.php` comprueba la página Mi perfil para usuarios de empresa y superadministrador, la API de perfil, el cambio de contraseña por HTTP, que la contraseña anterior deje de funcionar, que la nueva funcione, el CSRF y la restauración de la contraseña demo.

Las dos del módulo 13: `module13-smoke.php` valida el catálogo, la configuración automática, la sincronización idempotente de DTE, la emisión real con asiento automático, la invalidación con contra-asiento, el cuadre de todos los asientos, la partida doble, la anulación, el catálogo, los libros, la balanza, los estados financieros y las exportaciones CSV; `module13-http.php` comprueba la página, la API, el CSRF, la creación y anulación de asientos, el catálogo, la configuración, los libros, los estados financieros, la exportación CSV y los permisos de administrador, contador, vendedor y superadministrador.

Las dos del módulo 14: `module14-smoke.php` valida la configuración, las existencias calculadas, los movimientos manuales, el rechazo de salidas sin stock, el kardex, el descuento automático de una venta, la idempotencia, el stock negativo, la reposición por invalidación, la reconciliación, la eliminación en cascada y las exportaciones CSV; `module14-http.php` comprueba la página, la API, el CSRF, los movimientos, los permisos de administrador, vendedor y contador, el stock calculado en Productos y la exportación CSV.

Las dos del módulo 15: `modulo15-smoke.php` cambia el modo por empresa, rechaza DTE en modo interno y comprobantes internos en simulación, emite un comprobante `IN`, comprueba la serie `INT-`, la ausencia de código de generación, sello y JSON, el cálculo del IVA incluido, el descuento de inventario, el asiento de venta, el PDF/ticket no fiscales, el bloqueo de transmisión, correo y JSON, la anulación con reversión y contra-asiento, y la conservación del historial al volver a simulación; `modulo15-http.php` comprueba el cambio de modo desde el panel del superadministrador, el bloqueo de `PRODUCCION`, el formulario y la API en modo interno, la emisión, el PDF, el ticket, los rechazos de JSON/transmisión/correo, la anulación y la restauración del modo.

Las dos del módulo 16: `barcode-smoke.php` valida normalización, formato, unicidad, edición, búsqueda del catálogo, exposición en el formulario de factura y la restricción de la base; `barcode-http.php` comprueba el guardado, la búsqueda por texto y por código exacto, el rechazo de duplicados, la edición, la desactivación, el escaneo de inactivos y la eliminación.

Las dos de búsqueda inteligente: `smart-search-smoke.php` valida la condición de varios términos, orden indistinto, mayúsculas, acentos, documentos sin guiones y catálogos; `smart-search-http.php` comprueba la API de clientes, productos y catálogos con esas mismas búsquedas.

Las dos de ticket: `ticket-smoke.php` valida los formatos 80/58, los importes, el subtotal, el documento procesado, la marca de invalidación, el QR del ticket y el rechazo de un documento inexistente; `ticket-http.php` comprueba que el ticket exige sesión, que el administrador abre ambos formatos, que se muestran empresa, número de control, ítems, advertencia de simulación, QR, marca de invalidación, 404 y el enlace desde Documentos.

`qr-smoke.php` valida el generador QR: versión y matriz verificadas contra una implementación de referencia, PNG con zona de silencio, URL de consulta con ambiente de simulación/producción, ausencia de QR sin código de generación y unicidad entre documentos.

Todas eliminan sus datos y archivos temporales al finalizar, conservan los documentos existentes y devuelven los correlativos y la configuración a su valor inicial. `module7-smtp.php` usa únicamente `127.0.0.1` y no envía correo a Internet. `module6-pdf.php`, `module9-http.php` y las pruebas HTTP requieren permisos de escritura en `uploads/logos` y `tests/`; si el entorno está confinado, ejecútelas con acceso completo. Al cierre de esta versión la suite suma 726 comprobaciones correctas (módulo 15: 36 de consola y 28 HTTP; módulo 16: 12 de consola y 12 HTTP; búsqueda inteligente: 11 de consola y 5 HTTP; QR: 13; ticket: 20 de consola y 16 HTTP).

## Modelo de datos incluido

- `tenants`, `users`, `tenant_config` y `auth_attempts`: empresas, usuarios, configuración y seguridad de acceso. `tenants.activo` permite desactivar una empresa sin eliminar sus datos y `tenants.logo_path` guarda la ruta relativa del logo usado en el PDF.
- `users` es global: el superadministrador tiene `tenant_id` nulo y rol `SUPERADMIN`; los usuarios de empresa tienen `tenant_id` obligatorio y rol `ADMIN`, `VENDEDOR` o `CONTADOR`. El nombre de usuario es único en toda la instalación.
- `document_sequences`: correlativos independientes por empresa, tipo DTE y establecimiento.
- Catálogos `mh_*`: valores independientes por empresa, con unidades y formas de pago contrastadas con la documentación oficial recibida.
- `clientes` y `productos`: receptores y catálogo comercial con validación, estados y relaciones por empresa. El documento del cliente admite `NIT`, `DUI` o `NINGUNO`; en este último caso el número queda nulo y varios clientes pueden compartir esa condición. `productos.codigo_barras` es opcional y único por empresa; se usa para la búsqueda y el escaneo en la factura.
- `documentos`: cabecera, estado, totales, JSON DTE, firma, sello, invalidación, marca de contingencia (`tipo_operacion`, `tipo_contingencia`, `motivo_contingencia`, `contingencia_evento_id`), documento relacionado de las notas y datos de exportación. El tipo `IN` identifica los comprobantes internos no fiscales: no guardan JSON, firma, sello ni código de generación.
- `documento_items`: líneas con cantidades, precios, descuentos, valores fiscales de hasta 8 decimales e importe de compra del sujeto excluido.
- `eventos_contingencia`: cabecera del evento MH v4, periodo, motivo, responsable, JSON, respuesta, sello y estado.
- `evento_contingencia_documentos`: detalle ordenado de los DTE incluidos en cada evento.
- `mh_transmissions_log`: solicitudes y respuestas de firma, transmisión, consulta, invalidación y contingencia, con usuario y fecha.
- `documento_correos`: historial de envíos del DTE por correo, con destinatario, copia, asunto, transporte, estado, error y adjuntos.
- `configuracion_cambios`: bitácora de cambios de configuración y de contabilidad, con sección, detalle, usuario y fecha.
- `contabilidad_cuentas`: catálogo de cuentas por empresa, con código, tipo, naturaleza, movimiento y estado.
- `contabilidad_config`: cuentas automáticas usadas al contabilizar los DTE (caja, cuentas por cobrar, ventas, IVA, devoluciones, compras, cuentas por pagar y retención de renta).
- `contabilidad_asientos` y `contabilidad_partidas`: asientos con partida doble, estado, documento de origen, totales, anulación y detalle por cuenta.
- `contabilidad_secuencias`: numeración consecutiva de asientos por empresa.
- `inventario_config`: fecha de inicio del control de inventario por empresa.
- `inventario_movimientos`: libro de entradas, salidas y ajustes por producto, con documento de origen, motivo, usuario y fecha; el stock actual se calcula sumando estos movimientos a la existencia base del producto.
- `tenant_config`: entorno MH, **modo de facturación** (`INTERNO`, `SIMULACION` o `PRODUCCION` bloqueado), zona horaria, moneda, establecimiento, distrito, departamento y municipio del emisor.
- `schema_versions`: control de versiones del esquema; la versión actual es la 17.

Todas las tablas de negocio y catálogos incluyen `tenant_id` y las operaciones de los módulos 5 al 16 lo validan en cada consulta.

## Seguridad implementada

- Contraseñas con `password_hash()` y verificación con `password_verify()`, con rehash automático.
- PDO con sentencias preparadas nativas; sin concatenación de datos en SQL.
- Tokens CSRF en formularios y validación en cada operación POST.
- Regeneración del ID de sesión y bloqueo temporal de intentos fallidos.
- Inicio de sesión con usuario o correo; el correo se resuelve solo si identifica a una única cuenta activa. “Mantener sesión activa” usa una cookie HttpOnly de 30 días y regenera el ID de sesión al entrar.
- Escape de salida HTML y construcción segura del DOM en JavaScript.
- Encabezados CSP, `X-Frame-Options`, `X-Content-Type-Options` y `Referrer-Policy`.
- Autorización por rol en pantalla y API, y aislamiento por empresa también en descargas y en el PDF.
- Reserva de correlativos con transacción y bloqueo `FOR UPDATE`.
- Validación de entradas y de los JSON contra los esquemas MH incluidos.
- Logos validados por tipo real, tamaño y dimensiones; se reescriben como JPEG sobre fondo blanco y se guardan con nombre aleatorio. `uploads/logos/.htaccess` bloquea la ejecución de scripts y el listado del directorio.
- El PDF se genera en el servidor a partir del documento emitido; no acepta rutas ni plantillas del usuario y no expone datos de otras empresas.
- Correos con remitente y destinatarios validados; se rechazan direcciones con saltos de línea para evitar inyección de cabeceras. El asunto y los nombres se codifican en UTF-8 y los adjuntos se envían en base64.
- Las credenciales SMTP viven solo en variables de entorno, nunca en la base de datos ni en el historial de envíos. Los errores registrados no incluyen la contraseña.
- El envío de correo exige sesión, CSRF y rol `ADMIN` o `VENDEDOR`; el historial está aislado por empresa.
- Los reportes y su exportación filtran siempre por `tenant_id` y aceptan únicamente rangos de fecha válidos.
- La contabilidad exige sesión y rol `ADMIN` o `CONTADOR`; el catálogo y la configuración solo los modifica `ADMIN`. Cada operación se valida contra la empresa de la sesión, exige CSRF, comprueba cuentas activas y de movimiento y verifica que el debe sea igual al haber antes de guardar. Los asientos automáticos son idempotentes por documento y no revierten un DTE si falla la contabilidad.
- El inventario exige sesión; `ADMIN` y `VENDEDOR` registran movimientos y `CONTADOR` solo consulta. Cada movimiento valida producto, empresa, fecha, cantidad y motivo, exige CSRF y bloquea las salidas manuales que dejarían stock negativo. Las salidas automáticas de ventas pueden dejar stock negativo para no bloquear el DTE, y se muestran como alerta. El stock se calcula desde la existencia base y los movimientos, sin aceptar saldos arbitrarios desde la API.
- La configuración exige rol `ADMIN`, CSRF y sesión; cada cambio se audita sin guardar contraseñas. Se valida que el NIT no se repita, que el emisor use catálogos activos y que siempre quede un administrador activo.
- El superadministrador es un usuario global sin empresa; su sesión es distinta de la sesión de empresa y no puede entrar al dashboard de un tenant. Las APIs de empresa rechazan esa sesión y el panel global rechaza a los usuarios de empresa.
- El registro público está deshabilitado por completo: la pantalla y el endpoint responden con redirección o `403`; solo el superadministrador crea empresas.
- Las credenciales SMTP no se almacenan en la base de datos: solo se leen del archivo local o de las variables de entorno.

## Notas para los siguientes módulos

- La representación gráfica PDF del módulo 6 está implementada para Factura y Comprobante de Crédito Fiscal, incluidos documentos invalidados y de varias páginas. El módulo 10 extiende el PDF a Nota de Crédito, Nota de Débito, Exportación y Sujeto Excluido.
- El módulo 7 envía el DTE por correo al emitir y agrega dashboard y reportes. El transporte predeterminado es simulado; para producción configure `MAIL_*` con un proveedor SMTP.
- El módulo 8 administra empresa, emisor, numeración, correo y la consulta de usuarios. La creación y administración de usuarios está centralizada en el superadministrador (módulo 11). Departamento, municipio y distrito del emisor ya son configurables y se aplican a los nuevos DTE.
- El módulo 9 permite emitir DTE con `tipoOperacion=2` sin transmitirlos, agruparlos en un evento de contingencia del esquema MH v4 y transmitir el evento y los DTE pendientes en simulación. Solo `ADMIN` declara eventos; `ADMIN` y `VENDEDOR` emiten en contingencia; `CONTADOR` consulta.
- El módulo 10 habilita los tipos 05, 06, 11 y 14 con sus esquemas oficiales. Las notas exigen un documento relacionado procesado del mismo cliente; la exportación usa receptor extranjero, flete, seguro e incoterm; el sujeto excluido usa compra y retención de renta. Los reportes restan las notas de crédito.
- El módulo 11 agrega el superadministrador global: crea empresas con su primer administrador, asigna uno o muchos usuarios por empresa, restablece contraseñas y desactiva/reactiva empresas y usuarios. La gestión de usuarios es exclusiva del superadministrador; el `ADMIN` de empresa solo consulta la lista. El registro público quedó cerrado. La eliminación de una empresa es lógica (`tenants.activo = 0`) para conservar sus DTE y datos fiscales.
- El módulo 12 agrega **Mi perfil** para usuarios de empresa y para el superadministrador. Cada cuenta puede consultar sus datos y cambiar únicamente su propia contraseña, confirmando la actual; el cambio queda auditado en la empresa. No se permite cambiar nombre, usuario, correo, rol ni empresa desde el perfil.
- El módulo 13 agrega **Contabilidad** por empresa. El catálogo base se crea al abrir el módulo o al crear una empresa desde el superadministrador. Cada DTE procesado genera un asiento automático (venta, nota de crédito, nota de débito, exportación o sujeto excluido) y cada invalidación genera su contra-asiento; los documentos anteriores a la instalación se contabilizan desde “Generar asientos”. Los asientos manuales exigen partida doble y se pueden anular; los automáticos solo se revierten invalidando el DTE. `ADMIN` administra catálogo y configuración; `ADMIN` y `CONTADOR` registran asientos y consultan libros; `VENDEDOR` no tiene acceso. Queda pendiente para una etapa posterior el cierre de periodos, la contabilización automática de compras y el libro de IVA compras.
- El módulo 14 agrega **Inventario básico**. La existencia actual de cada producto se calcula sumando a su existencia base los movimientos de entrada, salida y ajuste. Los DTE 01, 03, 06 y 11 descuentan automáticamente las líneas de productos con control de existencias; la invalidación genera el movimiento inverso y la eliminación del documento elimina sus movimientos. Las notas de crédito y el sujeto excluido no mueven inventario: si hay una devolución física, se registra una entrada manual. `ADMIN` y `VENDEDOR` registran movimientos; `CONTADOR` consulta. Quedan pendientes las compras con IVA crédito, el costeo y los almacenes múltiples.
- El módulo 15 agrega el **modo de facturación por empresa**. `tenant_config.modo_facturacion` admite `INTERNO` y `SIMULACION`; `PRODUCCION` sigue bloqueado. Solo el superadministrador cambia el modo. En modo interno la empresa emite comprobantes `IN` con serie `INT-000001`, sin datos fiscales ni transmisión, pero con inventario y asiento contable. El comprobante no se convierte en DTE y la empresa puede volver a simulación sin perder su historial interno. El modo mixto dentro de una misma empresa no está implementado.
- El módulo 16 agrega el **código de barras por producto** y el **selector de producto con búsqueda**. Es opcional, único por empresa y se administra desde Productos. La factura incluye una barra de escaneo: con una pistola USB (teclado + Enter) agrega la línea o incrementa la cantidad si el producto ya está. Cada línea tiene un buscador que filtra por código, descripción o código de barras, con navegación por flechas y Enter. También se puede buscar por código de barras en el catálogo y consultar un producto activo con `api/productos.php?action=barcode&codigo=...`. No se implementaron códigos múltiples por producto, etiquetas ni lectura por cámara.
- La conexión real al Ministerio de Hacienda requiere endpoints, credenciales y un cliente que implemente `MHConnectorInterface`. Actualmente la fábrica solo permite `SIMULACION`; el módulo 8 no permite activar producción y los módulos 9 y 10 tampoco transmiten a Hacienda.
- En `svfe-json-schemas/` hay además esquemas para 04, 07, 08, 09, 15, 17 y 18; no están en el menú de emisión y quedan para una etapa posterior.
- El código QR del DTE se incorpora al PDF y al ticket. Codifica la URL de consulta pública del MH con el ambiente, el código de generación y la fecha de emisión. Se implementa en `lib/qr.php` con PHP puro (versiones 1 a 10, corrección M) y no requiere librerías externas. En modo interno no se muestra QR fiscal.
- Queda pendiente para una siguiente etapa la integración real con Hacienda (incluidos los endpoints de eventos), el resto de tipos DTE y el envío masivo o diferido de correos.
- En contabilidad quedan pendientes el cierre y reapertura de periodos, la contabilización automática de compras (IVA crédito fiscal), el libro de IVA compras y un estado de flujo de efectivo.
- En inventario quedan pendientes las compras, el costeo (promedio ponderado o PEPS), los almacenes múltiples, las transferencias entre almacenes y las alertas de stock mínimo.
- En autenticación quedan pendientes los magic links, las passkeys y el SSO/OAuth corporativo. El login actual ya admite usuario o correo, mostrar/ocultar contraseña y sesión persistente; las integraciones externas requieren proveedor de identidad, verificación de dominio y backend de tokens.
