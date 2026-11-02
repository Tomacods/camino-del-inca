<?php

namespace Database\Seeders;

use App\Enums\CategoriaValoracion;
use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Enums\TipoServicio;
use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Reserva;
use Carbon\CarbonInterface;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Datos de volumen para la presentación y para ver los listados con miles de filas: una empresa que opera el Camino Inca
// hace años. Las fechas son relativas al día en que se corre; lo demás sale siempre igual por la semilla fija. Las filas
// se escriben directo y de a bloques, sin pasar por los casos de uso: no se manda ningún correo ni se encola nada.
// DatabaseSeeder no lo llama en las pruebas.
class DatosDeVolumenSeeder extends Seeder
{
    // Cantidades: se pueden achicar para tener una base más chica.
    private const GUIAS = 45;

    private const PAQUETES = 20;

    private const PAQUETES_INACTIVOS = 4;

    private const MESES_HACIA_ATRAS = 14;

    private const MESES_HACIA_ADELANTE = 10;

    private const SEMILLA = 2026;

    private const FILAS_POR_BLOQUE = 500;

    // Porcentajes de las decisiones al azar.
    private const PORCENTAJE_CANCELADAS = 10;

    private const PORCENTAJE_PAGO_TOTAL = 40;

    private const PORCENTAJE_SIN_PERMISO = 15;

    private const PORCENTAJE_CLIENTES_QUE_VUELVEN = 5;

    private const PORCENTAJE_VALORADAS = 62;

    private const PORCENTAJE_SIN_COMENTARIO = 30;

    // Cuánto antes de la salida reserva, como mucho, un cliente.
    private const MESES_MAXIMOS_DE_ANTICIPACION = 11;

    // Un cliente que vuelve repite el correo en dos o tres reservas, no más.
    private const RESERVAS_MAXIMAS_POR_CLIENTE = 3;

    private const GUIAS_CON_QUEJAS = 4;

    private const CIFRAS_NUMERO_RESERVA = 6;

    private const CIFRAS_NUMERO_COMPROBANTE = 8;

    // De mayo a septiembre es la temporada seca, la más vendida; de diciembre a marzo llueve y se vende poco.
    private const MESES_DE_TEMPORADA_SECA = [5, 6, 7, 8, 9];

    private const MESES_DE_LLUVIA = [12, 1, 2, 3];

    // Ocupación de cada salida, en porcentaje del cupo: [mínimo, máximo].
    private const OCUPACION_TEMPORADA_SECA = [75, 100];

    private const OCUPACION_LLUVIAS = [25, 50];

    private const OCUPACION_RESTO_DEL_ANIO = [50, 75];

    // Integrantes por reserva => peso: la mayoría viaja de a dos.
    private const INTEGRANTES_POR_RESERVA = [1 => 13, 2 => 45, 3 => 12, 4 => 15, 5 => 6, 6 => 4, 7 => 3, 8 => 2];

    // Cupo de cada salida => peso: entre 8 y 16, más seguido de 12 a 14.
    private const CUPOS = [8 => 1, 9 => 1, 10 => 2, 11 => 2, 12 => 3, 13 => 3, 14 => 4, 15 => 3, 16 => 3];

    // Noches extra en Cusco (antes más después) => peso. A lo sumo config('reserva.maximo_noches_extra').
    private const NOCHES_EXTRA = [0 => 55, 1 => 28, 2 => 17];

    // Los textos que ya usan PagoSeeder y Pago::MEDIOS_DE_PAGO => peso.
    private const MEDIOS_DE_PAGO = [
        'Tarjeta de crédito' => 65,
        'Tarjeta de débito' => 20,
        'Dinero en cuenta de Mercado Pago' => 15,
    ];

    // Puntaje de 5 a 1 => peso. Las quejas y la lluvia bajan los puntajes, para que los filtros de CU-08 muestren
    // diferencias.
    private const PUNTAJES_HABITUALES = [5 => 50, 4 => 32, 3 => 11, 2 => 4, 1 => 3];

    private const PUNTAJES_REGULARES = [5 => 22, 4 => 36, 3 => 26, 2 => 10, 1 => 6];

    private const PUNTAJES_CON_QUEJAS = [5 => 5, 4 => 15, 3 => 35, 2 => 30, 1 => 15];

    // Cómo pagó una reserva.
    private const PAGO_TOTAL = 'total';

    private const PAGO_SENA = 'seña';

    private const PAGO_SENA_Y_SALDO = 'seña y saldo';

    // Cómo quedaron los permisos de sus integrantes.
    private const PERMISOS_PENDIENTES = 'pendientes';

    private const PERMISOS_OBTENIDOS = 'obtenidos';

    private const PERMISOS_CON_RECHAZO = 'con rechazo';

    // Por qué se canceló una reserva => peso. La causa no se guarda: sólo sirve para elegir las muestras.
    private const CAUSA_FALTA_DE_PAGO = 'falta de pago del saldo (CU-22)';

    private const CAUSA_FALTA_DE_PERMISOS = 'falta de confirmación de permisos (CU-26)';

    private const CAUSA_REINTEGRO = 'reintegro pedido desde Sin Permiso (CU-17)';

    private const CAUSA_VOLUNTARIA = 'cancelación voluntaria (CU-21)';

    private const PESO_POR_CAUSA = [
        self::CAUSA_FALTA_DE_PAGO => 25,
        self::CAUSA_FALTA_DE_PERMISOS => 20,
        self::CAUSA_REINTEGRO => 20,
        self::CAUSA_VOLUNTARIA => 35,
    ];

    private const CORREOS_ADMINISTRADORES = [
        'operaciones@caminodelinca.test',
        'ventas@caminodelinca.test',
        'permisos@caminodelinca.test',
        'gerencia@caminodelinca.test',
    ];

    // Los de GuiaSeeder: no se repiten sus nombres.
    private const GUIAS_ACTUALES = ['Rosa Quispe', 'Andrés Mamani', 'Julia Condori'];

    private const NOMBRES_GUIAS = [
        'Rosa', 'Andrés', 'Julia', 'Wilber', 'Edwin', 'Yesenia', 'Ruth', 'Hernán', 'Marleni', 'Fredy', 'Nilda', 'Rubén',
        'Lizbeth', 'Percy', 'Elvis', 'Maribel', 'Vilma', 'Grimaldo', 'Saúl', 'Yanet', 'Rocío', 'Abel', 'Ronald', 'Flor',
        'Edith', 'Washington', 'Hilda', 'Javier', 'Melchor', 'Teófilo', 'Gladys', 'Cirilo', 'Nancy', 'Marco', 'Rolando',
        'Aydé', 'Alberto', 'Delia', 'Isabel', 'Wilfredo',
    ];

    private const APELLIDOS_QUECHUAS = [
        'Quispe', 'Huamán', 'Mamani', 'Condori', 'Ccahua', 'Huillca', 'Apaza', 'Choque', 'Yupanqui', 'Tupa', 'Ccori',
        'Puma', 'Sutta', 'Cusi', 'Ayma', 'Chura', 'Taipe', 'Huanca', 'Ccallo', 'Hancco', 'Yucra', 'Mayta', 'Quille',
        'Ttito', 'Achahui', 'Pumacahua', 'Huallpa', 'Ccoa', 'Layme', 'Cutipa',
    ];

    private const NOMBRES = [
        'Sofía', 'Martina', 'Valentina', 'Camila', 'Lucía', 'Isabella', 'Florencia', 'Agustina', 'Mariana', 'Daniela',
        'Gabriela', 'Carolina', 'Paula', 'Victoria', 'Renata', 'Antonella', 'Ximena', 'Fernanda', 'Catalina', 'Josefina',
        'Lorena', 'Elena', 'Carmen', 'Pilar', 'Andrea', 'Santiago', 'Mateo', 'Matías', 'Benjamín', 'Sebastián',
        'Nicolás', 'Joaquín', 'Tomás', 'Diego', 'Alejandro', 'Emiliano', 'Felipe', 'Juan Pablo', 'Gonzalo', 'Rodrigo',
        'Facundo', 'Andrés', 'Pablo', 'Martín', 'Ignacio', 'Javier', 'Luis', 'Manuel', 'Hugo', 'Rafael',
    ];

    private const APELLIDOS = [
        'González', 'Rodríguez', 'Gómez', 'Fernández', 'López', 'Díaz', 'Martínez', 'Pérez', 'García', 'Sánchez',
        'Romero', 'Sosa', 'Álvarez', 'Torres', 'Ruiz', 'Ramírez', 'Flores', 'Acosta', 'Benítez', 'Medina', 'Herrera',
        'Suárez', 'Aguirre', 'Giménez', 'Gutiérrez', 'Pereyra', 'Rojas', 'Molina', 'Castro', 'Ortiz', 'Silva', 'Núñez',
        'Luna', 'Cabrera', 'Ríos', 'Morales', 'Vargas', 'Muñoz', 'Castillo', 'Mendoza', 'Reyes', 'Jiménez', 'Navarro',
        'Vega', 'Cárdenas', 'Salazar', 'Ibáñez', 'Fuentes', 'Carrasco', 'Olivera',
    ];

    // Dominios reservados para ejemplos: el Temporizador nunca le escribe a una persona real.
    private const DOMINIOS_DE_EJEMPLO = ['example.com', 'example.org', 'example.net'];

    // Pasaportes y documentos de Argentina, Chile, México, Colombia, España, Uruguay, Perú y Estados Unidos: «?» es
    // una letra y «#», una cifra.
    private const FORMATOS_DOCUMENTO = [
        '???######', '?########', 'G########', '??######', '???######', 'C#######', 'P########', 'A########',
    ];

    private const SERVICIOS_NUEVOS = [
        TipoServicio::Hotel->value => [
            'Hostal en Cusco',
            'Hotel boutique en Cusco',
            'Hotel de lujo en Cusco',
            'Hostal en Aguas Calientes',
            'Hotel boutique en Aguas Calientes',
            'Hotel de lujo en Aguas Calientes',
        ],
        TipoServicio::TransporteEnBus->value => [
            'Bus privado de Cusco al Kilómetro 82',
            'Bus privado de Ollantaytambo a Cusco',
        ],
        TipoServicio::TransporteFerroviario->value => [
            'Tren panorámico de Ollantaytambo al Kilómetro 104',
            'Tren panorámico de Aguas Calientes a Ollantaytambo',
        ],
    ];

    // Los cuatro campings de ServicioSeeder, en el orden en que se usan: el de 4 días lleva los tres primeros, como
    // «Camino Inca Clásico»; el de 5, los cuatro.
    private const CAMPINGS_DE_ETAPA = ['Camping Wayllabamba', 'Camping Pacaymayo', 'Camping Wiñay Wayna', 'Camping Ayapata'];

    // Los servicios de cada categoría de paquete.
    private const SERVICIOS_POR_CATEGORIA = [
        'económica' => [
            'hotel_cusco' => 'Hostal en Cusco',
            'hotel_aguas_calientes' => 'Hostal en Aguas Calientes',
            'bus_ida' => 'Bus de Cusco al Kilómetro 82',
            'tren_ida' => 'Tren de Ollantaytambo al Kilómetro 104',
            'tren_vuelta' => 'Tren de Aguas Calientes a Ollantaytambo',
            'bus_vuelta' => 'Bus de Ollantaytambo a Cusco',
        ],
        'estándar' => [
            'hotel_cusco' => 'Hotel en Cusco',
            'hotel_aguas_calientes' => 'Hotel en Aguas Calientes',
            'bus_ida' => 'Bus de Cusco al Kilómetro 82',
            'tren_ida' => 'Tren de Ollantaytambo al Kilómetro 104',
            'tren_vuelta' => 'Tren de Aguas Calientes a Ollantaytambo',
            'bus_vuelta' => 'Bus de Ollantaytambo a Cusco',
        ],
        'superior' => [
            'hotel_cusco' => 'Hotel boutique en Cusco',
            'hotel_aguas_calientes' => 'Hotel boutique en Aguas Calientes',
            'bus_ida' => 'Bus de Cusco al Kilómetro 82',
            'tren_ida' => 'Tren panorámico de Ollantaytambo al Kilómetro 104',
            'tren_vuelta' => 'Tren panorámico de Aguas Calientes a Ollantaytambo',
            'bus_vuelta' => 'Bus de Ollantaytambo a Cusco',
        ],
        'premium' => [
            'hotel_cusco' => 'Hotel de lujo en Cusco',
            'hotel_aguas_calientes' => 'Hotel de lujo en Aguas Calientes',
            'bus_ida' => 'Bus privado de Cusco al Kilómetro 82',
            'tren_ida' => 'Tren panorámico de Ollantaytambo al Kilómetro 104',
            'tren_vuelta' => 'Tren panorámico de Aguas Calientes a Ollantaytambo',
            'bus_vuelta' => 'Bus privado de Ollantaytambo a Cusco',
        ],
    ];

    // Dos de PaqueteSeeder también reciben salidas nuevas: nombre => cada cuántas semanas sale. «Camino Inca Extendido»
    // queda como estaba, sin reservas activas, para poder mostrar Modificar Paquete y Dar de Baja (R-02 y R-03).
    private const FRECUENCIA_PAQUETES_ACTUALES = [
        'Camino Inca Clásico' => 1,
        'Camino Inca Corto' => 2,
    ];

    // Precios en USD por persona. Los más vendidos salen todas las semanas; el resto, cada dos o cuatro. La antigüedad
    // es cuántos meses antes de hoy se dio de alta.
    private const PAQUETES_ACTIVOS = [
        ['nombre' => 'Camino Inca Express', 'dias' => 2, 'categoria' => 'estándar', 'precio_base' => 520, 'costo_noche_extra_cusco' => 65, 'costo_equipo_camping' => 0, 'cantidad_porteadores' => 0, 'cada_semanas' => 1, 'meses_de_antiguedad' => 34],
        ['nombre' => 'Camino Inca Mochilero', 'dias' => 4, 'categoria' => 'económica', 'precio_base' => 690, 'costo_noche_extra_cusco' => 45, 'costo_equipo_camping' => 35, 'cantidad_porteadores' => 4, 'cada_semanas' => 1, 'meses_de_antiguedad' => 32, 'quejas_hotel' => true],
        ['nombre' => 'Camino Inca Tradicional', 'dias' => 4, 'categoria' => 'estándar', 'precio_base' => 780, 'costo_noche_extra_cusco' => 60, 'costo_equipo_camping' => 40, 'cantidad_porteadores' => 6, 'cada_semanas' => 1, 'meses_de_antiguedad' => 36],
        ['nombre' => 'Camino Inca Premium', 'dias' => 4, 'categoria' => 'premium', 'precio_base' => 1390, 'costo_noche_extra_cusco' => 175, 'costo_equipo_camping' => 65, 'cantidad_porteadores' => 12, 'cada_semanas' => 2, 'meses_de_antiguedad' => 30],
        ['nombre' => 'Camino Inca Familiar', 'dias' => 4, 'categoria' => 'estándar', 'precio_base' => 820, 'costo_noche_extra_cusco' => 70, 'costo_equipo_camping' => 45, 'cantidad_porteadores' => 8, 'cada_semanas' => 1, 'meses_de_antiguedad' => 29],
        ['nombre' => 'Camino Inca Andino', 'dias' => 5, 'categoria' => 'estándar', 'precio_base' => 980, 'costo_noche_extra_cusco' => 70, 'costo_equipo_camping' => 45, 'cantidad_porteadores' => 9, 'cada_semanas' => 2, 'meses_de_antiguedad' => 33],
        ['nombre' => 'Camino Inca Inti Punku', 'dias' => 2, 'categoria' => 'superior', 'precio_base' => 640, 'costo_noche_extra_cusco' => 110, 'costo_equipo_camping' => 0, 'cantidad_porteadores' => 0, 'cada_semanas' => 2, 'meses_de_antiguedad' => 28],
        ['nombre' => 'Camino Inca Ancestral', 'dias' => 4, 'categoria' => 'superior', 'precio_base' => 1050, 'costo_noche_extra_cusco' => 95, 'costo_equipo_camping' => 50, 'cantidad_porteadores' => 8, 'cada_semanas' => 2, 'meses_de_antiguedad' => 27],
        ['nombre' => 'Camino Inca Económico', 'dias' => 2, 'categoria' => 'económica', 'precio_base' => 450, 'costo_noche_extra_cusco' => 45, 'costo_equipo_camping' => 0, 'cantidad_porteadores' => 0, 'cada_semanas' => 2, 'meses_de_antiguedad' => 31],
        ['nombre' => 'Camino Inca Fotográfico', 'dias' => 5, 'categoria' => 'superior', 'precio_base' => 1250, 'costo_noche_extra_cusco' => 110, 'costo_equipo_camping' => 55, 'cantidad_porteadores' => 10, 'cada_semanas' => 4, 'meses_de_antiguedad' => 26],
        ['nombre' => 'Camino Inca Aventura', 'dias' => 5, 'categoria' => 'económica', 'precio_base' => 900, 'costo_noche_extra_cusco' => 50, 'costo_equipo_camping' => 35, 'cantidad_porteadores' => 6, 'cada_semanas' => 4, 'meses_de_antiguedad' => 35],
        ['nombre' => 'Camino Inca Lujo', 'dias' => 5, 'categoria' => 'premium', 'precio_base' => 1780, 'costo_noche_extra_cusco' => 180, 'costo_equipo_camping' => 65, 'cantidad_porteadores' => 14, 'cada_semanas' => 4, 'meses_de_antiguedad' => 25],
        ['nombre' => 'Camino Inca Estudiantil', 'dias' => 4, 'categoria' => 'económica', 'precio_base' => 720, 'costo_noche_extra_cusco' => 45, 'costo_equipo_camping' => 35, 'cantidad_porteadores' => 5, 'cada_semanas' => 4, 'meses_de_antiguedad' => 24],
        ['nombre' => 'Camino Inca Ruta de los Apus', 'dias' => 5, 'categoria' => 'superior', 'precio_base' => 1450, 'costo_noche_extra_cusco' => 120, 'costo_equipo_camping' => 60, 'cantidad_porteadores' => 12, 'cada_semanas' => 4, 'meses_de_antiguedad' => 26],
        // Los dos más nuevos: sus salidas empiezan tres meses después del alta.
        ['nombre' => 'Camino Inca Luna Llena', 'dias' => 4, 'categoria' => 'superior', 'precio_base' => 1100, 'costo_noche_extra_cusco' => 100, 'costo_equipo_camping' => 50, 'cantidad_porteadores' => 8, 'cada_semanas' => 4, 'meses_de_antiguedad' => 5],
        ['nombre' => 'Camino Inca Amanecer en Machu Picchu', 'dias' => 2, 'categoria' => 'premium', 'precio_base' => 700, 'costo_noche_extra_cusco' => 160, 'costo_equipo_camping' => 0, 'cantidad_porteadores' => 0, 'cada_semanas' => 4, 'meses_de_antiguedad' => 3],
    ];

    // Dados de baja (R-03): su última salida fue meses antes de hoy, así que no les queda ninguna reserva vigente.
    private const PAQUETES_DADOS_DE_BAJA = [
        ['nombre' => 'Camino Inca Temporada Seca', 'dias' => 4, 'categoria' => 'estándar', 'precio_base' => 760, 'costo_noche_extra_cusco' => 60, 'costo_equipo_camping' => 40, 'cantidad_porteadores' => 6, 'cada_semanas' => 2, 'meses_de_antiguedad' => 38, 'meses_desde_la_baja' => 3],
        ['nombre' => 'Camino Inca Promocional', 'dias' => 2, 'categoria' => 'económica', 'precio_base' => 460, 'costo_noche_extra_cusco' => 45, 'costo_equipo_camping' => 0, 'cantidad_porteadores' => 0, 'cada_semanas' => 4, 'meses_de_antiguedad' => 37, 'meses_desde_la_baja' => 5],
        ['nombre' => 'Camino Inca Grupal', 'dias' => 4, 'categoria' => 'económica', 'precio_base' => 700, 'costo_noche_extra_cusco' => 45, 'costo_equipo_camping' => 35, 'cantidad_porteadores' => 5, 'cada_semanas' => 2, 'meses_de_antiguedad' => 36, 'meses_desde_la_baja' => 2],
        ['nombre' => 'Camino Inca Expedición', 'dias' => 5, 'categoria' => 'estándar', 'precio_base' => 1020, 'costo_noche_extra_cusco' => 70, 'costo_equipo_camping' => 45, 'cantidad_porteadores' => 9, 'cada_semanas' => 4, 'meses_de_antiguedad' => 39, 'meses_desde_la_baja' => 6],
    ];

    private const COMENTARIOS_POSITIVOS = [
        'Una experiencia inolvidable. La llegada a Machu Picchu por la Puerta del Sol vale cada paso del camino.',
        'Todo muy bien organizado, desde el traslado en Cusco hasta la vuelta en tren. Lo recomiendo sin dudas.',
        'Los porteadores son admirables: llegaban antes que nosotros y ya tenían todo listo para descansar.',
        'Superó nuestras expectativas. Las explicaciones sobre la historia de cada sitio inca fueron excelentes.',
        'Muy buena atención durante todo el recorrido. La comida, sorprendentemente rica para estar en plena montaña.',
        'Fuimos en familia con dos adolescentes y lo disfrutaron muchísimo. Buen ritmo, con paradas para descansar.',
        'Hay tramos duros, pero el equipo nos acompañó en todo momento. Valió la pena cada subida.',
        'Excelente relación entre precio y calidad. Volvería a contratar con ustedes para otro destino.',
        'Nos encantó. Cumplieron con los horarios y la información previa al viaje fue muy clara.',
        'Paisajes increíbles y un grupo muy lindo. El amanecer en Inti Punku nos va a quedar para siempre.',
        'Muy profesionales. Nos ayudaron con la altura y estuvieron atentos a cada integrante del grupo.',
        'Viajé sola y me sentí cuidada y acompañada. Una experiencia que le recomiendo a cualquiera.',
        'El camino es exigente pero hermoso, y la organización estuvo a la altura. Muy conformes.',
        'Gran experiencia. Todo el equipo que nos dieron estaba en muy buen estado.',
        'Todo impecable. Las explicaciones en cada parada le dieron otro sentido al recorrido.',
        'Lo mejor de nuestro viaje a Perú. Gracias por la paciencia y la buena onda durante todo el camino.',
    ];

    // A veces se suman a un comentario positivo, para que no se noten repetidos.
    private const DETALLES_POSITIVOS = [
        'El tren de vuelta, muy cómodo.',
        'Ojo con el frío de la noche: lleven buen abrigo.',
        'Recomiendo sumar una noche extra en Cusco para aclimatarse.',
        'Los bastones de caminata ayudan mucho en las bajadas.',
        'Ya estamos pensando en volver.',
        'La comunicación por correo antes del viaje fue rápida y clara.',
        'El desayuno del último día, antes de subir a Inti Punku, fue un lujo.',
        'Lleven repelente: en la última parte hay muchos mosquitos.',
        'Las fotos en Machu Picchu salieron espectaculares.',
        'Muy útil la charla informativa del primer día.',
    ];

    private const COMENTARIOS_REGULARES = [
        'Lindo recorrido, aunque la organización del primer día fue algo desprolija y salimos con demora.',
        'El camino es hermoso, pero los baños de las paradas dejan bastante que desear.',
        'Buena experiencia en general. El bus de vuelta a Cusco llegó tarde y perdimos parte de la tarde.',
        'Cumplió con lo prometido, sin más. Esperaba un poco más de información sobre los sitios.',
        'La comida fue repetitiva y algo escasa para el esfuerzo que exige la caminata.',
        'Bien, pero el grupo era grande y a veces costaba escuchar las explicaciones.',
        'El recorrido vale la pena; el tren de vuelta, en cambio, fue incómodo y salió con mucha demora.',
    ];

    private const COMENTARIOS_NEGATIVOS = [
        'No volvería a contratar. Hubo cambios de horario sin aviso y nadie nos dio una explicación.',
        'Pagamos por un servicio que no se cumplió: faltó un traslado y tuvimos que tomar un taxi.',
        'Mala coordinación entre el tren y el bus: esperamos más de dos horas en Aguas Calientes.',
        'El precio no se corresponde con lo que recibimos. Esperábamos mucho más.',
        'Hubo poca atención cuando uno de nosotros se descompuso por la altura.',
    ];

    private const COMENTARIOS_SOBRE_EL_GUIA = [
        'Las explicaciones fueron muy pobres y el ritmo, demasiado rápido para el grupo. Faltó acompañamiento.',
        'Quien nos guiaba parecía apurado todo el tiempo y casi no respondía preguntas. El paisaje, en cambio, increíble.',
        'Faltó liderazgo en el grupo: nos separamos varias veces y nadie esperaba a los más lentos.',
        'Esperábamos más del guiado. Mucha de la información la terminamos buscando nosotros en internet.',
    ];

    private const COMENTARIOS_SOBRE_EL_HOTEL = [
        'El hotel no estaba a la altura: habitación fría, ruidosa y con agua caliente sólo a ratos.',
        'El alojamiento fue lo peor del viaje: poca limpieza y un desayuno muy pobre.',
        'El camino, excelente; el hotel, muy por debajo de lo esperado. Cambiaría de alojamiento.',
    ];

    private const COMENTARIOS_CON_LLUVIA = [
        'Llovió casi todo el recorrido y el camino estaba resbaloso. No es culpa de la agencia, pero convendría avisar mejor.',
        'Con la lluvia y la niebla no vimos casi nada desde los miradores. Igual, Machu Picchu impresiona.',
        'No recomiendo ir en temporada de lluvias: la ropa no se secaba nunca y el frío se sentía mucho más.',
        'Mucho barro en las bajadas. Habría venido bien que nos avisaran que lleváramos polainas.',
    ];

    // Tablas con clave autoincremental, para ajustar sus secuencias en PostgreSQL.
    private const CLAVES_AUTOINCREMENTALES = [
        'usuario' => 'id_usuario',
        'servicio' => 'id_servicio',
        'paquete' => 'id_paquete',
        'excursion' => 'id_excursion',
        'reserva' => 'id_reserva',
        'excursionista' => 'id_excursionista',
        'pago' => 'id_pago',
        'comprobante' => 'id_comprobante',
        'devolucion' => 'id_devolucion',
        'valoracion' => 'id_valoracion',
    ];

    private const TABLAS = [
        'usuario', 'guia', 'recorrido', 'etapa', 'servicio', 'paquete', 'paquete_servicio', 'excursion', 'reserva',
        'excursionista', 'pago', 'comprobante', 'devolucion', 'valoracion', 'detalle_valoracion',
    ];

    private Generator $faker;

    private Carbon $ahora;

    private Carbon $hoy;

    // El primer y el último lunes con salidas.
    private Carbon $primeraSalida;

    private Carbon $ultimaSalida;

    // Lo que se va armando en memoria, para insertarlo de a bloques y para elegir las muestras del final.
    private array $guias = [];

    private array $idServicioPorNombre = [];

    private array $paquetes = [];

    private array $excursiones = [];

    private array $reservas = [];

    private array $clientes = [];

    public function run(): void
    {
        mt_srand(self::SEMILLA);
        $this->faker = Faker::create();
        $this->faker->seed(self::SEMILLA);

        $this->ahora = now();
        $this->hoy = today();
        $desde = $this->hoy->copy()->subMonthsNoOverflow(self::MESES_HACIA_ATRAS);
        $this->primeraSalida = $desde->isMonday() ? $desde : $desde->next(CarbonInterface::MONDAY);
        $this->ultimaSalida = $this->hoy->copy()->addMonthsNoOverflow(self::MESES_HACIA_ADELANTE);

        $guias = $this->armarGuias();

        if (DB::table('usuario')->whereIn('correo', [...self::CORREOS_ADMINISTRADORES, ...array_column($guias, 'correo')])->exists()) {
            $this->command?->warn('Los datos de volumen ya están cargados: no se agrega nada.');

            return;
        }

        DB::transaction(function () use ($guias) {
            $this->crearAdministradores();
            $this->crearGuias($guias);
            $this->crearServicios();
            $this->crearPaquetes();
            $this->crearExcursiones();
            $this->crearReservas();
            $this->crearValoraciones();
            $this->ajustarSecuencias();
        });

        $this->mostrarResumen();
    }

    /* -------------------------------- Usuarios -------------------------------- */

    // Con DB::table no se aplica el cast «hashed» de Usuario: la contraseña se cifra una vez y se usa en todas las filas.
    private function crearAdministradores(): void
    {
        $contrasenia = Hash::make('admin1234');
        $idUsuario = $this->siguienteId('usuario', 'id_usuario');
        $filas = [];

        foreach (self::CORREOS_ADMINISTRADORES as $correo) {
            $filas[] = ['id_usuario' => $idUsuario++, 'correo' => $correo, 'password' => $contrasenia, 'rol' => Rol::Administrador->value];
        }

        $this->insertarEnBloques('usuario', $filas);
    }

    // Nombre en español y apellido quechua, como en la zona de Cusco. El correo va sin tildes ni eñes.
    private function armarGuias(): array
    {
        $guias = [];

        while (count($guias) < self::GUIAS) {
            $nombre = $this->faker->randomElement(self::NOMBRES_GUIAS);
            $apellido = $this->faker->randomElement(self::APELLIDOS_QUECHUAS);
            $correo = Str::slug($nombre.' '.$apellido, '.').'@caminodelinca.test';

            if (! isset($guias[$correo]) && ! in_array($nombre.' '.$apellido, self::GUIAS_ACTUALES, true)) {
                $guias[$correo] = ['nombre' => $nombre, 'apellido' => $apellido, 'correo' => $correo];
            }
        }

        return array_values($guias);
    }

    // Cada guía tiene un peso de 1 a 5: uno de peso 5 recibe cinco veces más salidas que uno de peso 1. Algunos tienen
    // quejas: sus puntajes de «Guía» salen bajos en las valoraciones.
    private function crearGuias(array $guias): void
    {
        $contrasenia = Hash::make('guia1234');
        $idUsuario = $this->siguienteId('usuario', 'id_usuario');
        $usuarios = [];
        $filasGuia = [];

        foreach ($guias as $guia) {
            $usuarios[] = ['id_usuario' => $idUsuario, 'correo' => $guia['correo'], 'password' => $contrasenia, 'rol' => Rol::Guia->value];
            $filasGuia[] = ['id_usuario' => $idUsuario, 'nombre' => $guia['nombre'], 'apellido' => $guia['apellido']];
            $this->guias[$idUsuario] = ['correo' => $guia['correo'], 'peso' => $this->faker->numberBetween(1, 5), 'con_quejas' => false];
            $idUsuario++;
        }

        foreach ($this->faker->randomElements(array_keys($this->guias), self::GUIAS_CON_QUEJAS) as $idGuia) {
            $this->guias[$idGuia]['con_quejas'] = true;
        }

        $this->insertarEnBloques('usuario', $usuarios);
        $this->insertarEnBloques('guia', $filasGuia);
    }

    /* ------------------------------- Servicios -------------------------------- */

    private function crearServicios(): void
    {
        $idServicio = $this->siguienteId('servicio', 'id_servicio');
        $filas = [];

        foreach (self::SERVICIOS_NUEVOS as $tipo => $nombres) {
            foreach ($nombres as $nombre) {
                $filas[] = ['id_servicio' => $idServicio++, 'tipo' => $tipo, 'nombre' => $nombre];
            }
        }

        $this->insertarEnBloques('servicio', $filas);
        $this->idServicioPorNombre = DB::table('servicio')->pluck('id_servicio', 'nombre')->all();
    }

    /* -------------------------------- Paquetes -------------------------------- */

    private function crearPaquetes(): void
    {
        // Los de PaqueteSeeder, con la misma regla que los nuevos: salidas y reservas después del alta.
        foreach (self::FRECUENCIA_PAQUETES_ACTUALES as $nombre => $cadaSemanas) {
            $paquete = Paquete::with(['recorrido', 'excursiones'])->where('nombre', $nombre)->firstOrFail();

            $this->paquetes[] = [
                'id_paquete' => $paquete->id_paquete,
                'nombre' => $nombre,
                'modelo' => $paquete,
                'id_recorrido' => $paquete->id_recorrido,
                'dias' => $paquete->recorrido->duracion_dias,
                'cada_semanas' => $cadaSemanas,
                'salidas_desde' => $paquete->fecha_creacion->copy()->addMonthsNoOverflow(config('reserva.meses_anticipacion_minima'))->max($this->primeraSalida),
                'salidas_hasta' => $this->ultimaSalida,
                'reservas_desde' => $paquete->fecha_creacion->copy()->addDay(),
                // id_paquete + fecha_salida es único: se saltean las fechas que ya tiene.
                'fechas_tomadas' => $paquete->excursiones->map(fn ($excursion) => $excursion->fecha_salida->toDateString())->all(),
                'quejas_hotel' => false,
            ];
        }

        $definiciones = [
            ...array_slice(self::PAQUETES_ACTIVOS, 0, self::PAQUETES - self::PAQUETES_INACTIVOS),
            ...array_slice(self::PAQUETES_DADOS_DE_BAJA, 0, self::PAQUETES_INACTIVOS),
        ];
        $recorridoPorDias = DB::table('recorrido')->get()->keyBy('duracion_dias');
        $idPaquete = $this->siguienteId('paquete', 'id_paquete');
        $filasPaquete = [];
        $filasServicio = [];

        foreach ($definiciones as $definicion) {
            $activo = ! isset($definicion['meses_desde_la_baja']);
            $recorrido = $recorridoPorDias[$definicion['dias']];
            $fechaCreacion = $this->hoy->copy()
                ->subMonthsNoOverflow($definicion['meses_de_antiguedad'])
                ->subDays($this->faker->numberBetween(0, 25));

            $filasPaquete[] = [
                'id_paquete' => $idPaquete,
                'id_recorrido' => $recorrido->id_recorrido,
                'nombre' => $definicion['nombre'],
                'precio_base' => $definicion['precio_base'],
                'costo_noche_extra_cusco' => $definicion['costo_noche_extra_cusco'],
                'costo_equipo_camping' => $definicion['costo_equipo_camping'],
                'cantidad_porteadores' => $definicion['cantidad_porteadores'],
                'estado' => ($activo ? EstadoPaquete::Activo : EstadoPaquete::Inactivo)->value,
                'fecha_creacion' => $fechaCreacion->toDateString(),
            ];

            foreach ($this->nombresServicios($definicion, $recorrido->cantidad_campings) as $nombreServicio) {
                $filasServicio[] = ['id_paquete' => $idPaquete, 'id_servicio' => $this->idServicioPorNombre[$nombreServicio]];
            }

            $this->paquetes[] = [
                'id_paquete' => $idPaquete,
                'nombre' => $definicion['nombre'],
                // Sin guardar: sólo para calcular el monto con Paquete::calcularMonto().
                'modelo' => new Paquete(Arr::only($definicion, ['precio_base', 'costo_noche_extra_cusco', 'costo_equipo_camping'])),
                'id_recorrido' => $recorrido->id_recorrido,
                'dias' => $definicion['dias'],
                'cada_semanas' => $definicion['cada_semanas'],
                // La primera salida, cuando ya se podía reservar: la anticipación mínima después del alta.
                'salidas_desde' => $fechaCreacion->copy()->addMonthsNoOverflow(config('reserva.meses_anticipacion_minima'))->max($this->primeraSalida),
                // Un paquete dado de baja sólo tiene salidas que ya terminaron (R-03).
                'salidas_hasta' => $activo ? $this->ultimaSalida : $this->hoy->copy()->subMonthsNoOverflow($definicion['meses_desde_la_baja']),
                'reservas_desde' => $fechaCreacion->copy()->addDay(),
                'fechas_tomadas' => [],
                'quejas_hotel' => $definicion['quejas_hotel'] ?? false,
            ];

            $idPaquete++;
        }

        $this->insertarEnBloques('paquete', $filasPaquete);
        $this->insertarEnBloques('paquete_servicio', $filasServicio);
    }

    // Como en PaqueteSeeder. El de 2 días, como «Camino Inca Corto»: tren al Kilómetro 104 y hotel en Aguas Calientes,
    // sin campings. Los de 4 y 5 días, como «Clásico» y «Extendido»: hotel en Cusco, bus al Kilómetro 82 y tantos
    // campings como tenga el recorrido. Los hoteles y los transportes cambian con la categoría.
    private function nombresServicios(array $definicion, int $cantidadCampings): array
    {
        $servicios = self::SERVICIOS_POR_CATEGORIA[$definicion['categoria']];

        if ($cantidadCampings === 0) {
            return [
                $servicios['tren_ida'],
                'Bus de Machu Picchu a Aguas Calientes',
                $servicios['hotel_aguas_calientes'],
                $servicios['tren_vuelta'],
                $servicios['bus_vuelta'],
            ];
        }

        return [
            $servicios['hotel_cusco'],
            $servicios['bus_ida'],
            ...array_slice(self::CAMPINGS_DE_ETAPA, 0, $cantidadCampings),
            'Bus de Machu Picchu a Aguas Calientes',
            $servicios['tren_vuelta'],
            $servicios['bus_vuelta'],
        ];
    }

    /* ------------------------------- Excursiones ------------------------------ */

    // Recorre los lunes (R-01) entre la primera y la última salida. Cada paquete sale cada una, dos o cuatro semanas; su
    // posición en la lista corre las salidas, para que no coincidan todas la misma semana.
    private function crearExcursiones(): void
    {
        $etapasPorRecorrido = DB::table('etapa')->orderBy('orden')->get()->groupBy('id_recorrido')
            ->map(fn ($etapas) => $etapas->pluck('id_etapa')->all());
        $pesoPorGuia = array_map(fn (array $guia) => $guia['peso'], $this->guias);
        $idExcursion = $this->siguienteId('excursion', 'id_excursion');
        $filas = [];

        for ($lunes = $this->primeraSalida->copy(), $semana = 0; $lunes->lessThanOrEqualTo($this->ultimaSalida); $lunes->addWeek(), $semana++) {
            // El Camino Inca cierra en febrero por mantenimiento.
            if ($lunes->month === 2) {
                continue;
            }

            // R-01: todas salen en lunes y ningún recorrido dura más de 5 días, así que alcanza con que un guía tenga a lo
            // sumo una salida por semana.
            $guiasDeLaSemana = [];

            foreach ($this->paquetes as $indicePaquete => $paquete) {
                if (($semana + $indicePaquete) % $paquete['cada_semanas'] !== 0
                    || $lunes->lessThan($paquete['salidas_desde'])
                    || $lunes->greaterThan($paquete['salidas_hasta'])
                    || in_array($lunes->toDateString(), $paquete['fechas_tomadas'], true)) {
                    continue;
                }

                $guiasLibres = array_diff_key($pesoPorGuia, $guiasDeLaSemana);

                // Con pocos guías, una semana puede quedarse sin ninguno libre: esa salida no se crea.
                if ($guiasLibres === []) {
                    continue;
                }

                $idGuia = $this->elegirConPesos($guiasLibres);
                $guiasDeLaSemana[$idGuia] = true;

                $fechaSalida = $lunes->copy();
                $ultimoDia = $fechaSalida->copy()->addDays($paquete['dias'] - 1);
                $cupo = $this->elegirConPesos(self::CUPOS);
                [$inicioRecorrido, $idEtapaActual] = $this->calcularAvance($fechaSalida, $ultimoDia, $paquete['dias'], $etapasPorRecorrido[$paquete['id_recorrido']]);

                $filas[] = [
                    'id_excursion' => $idExcursion,
                    'id_paquete' => $paquete['id_paquete'],
                    'id_guia' => $idGuia,
                    'id_etapa_actual' => $idEtapaActual,
                    'fecha_salida' => $fechaSalida->toDateString(),
                    'cupo' => $cupo,
                    'plazas_retenidas' => 0,
                    'fecha_hora_inicio_recorrido' => $inicioRecorrido?->toDateTimeString(),
                ];

                $this->excursiones[] = [
                    'id_excursion' => $idExcursion,
                    'paquete' => $indicePaquete,
                    'id_guia' => $idGuia,
                    'fecha_salida' => $fechaSalida,
                    'ultimo_dia' => $ultimoDia,
                    'cupo' => $cupo,
                    'iniciada' => $inicioRecorrido !== null,
                ];

                $idExcursion++;
            }
        }

        // Las filas y $this->excursiones se arman juntas: tienen los mismos índices.
        $indiceSinIniciar = $this->buscarEnCursoQueTerminaMasTarde();

        if ($indiceSinIniciar !== null) {
            $filas[$indiceSinIniciar]['fecha_hora_inicio_recorrido'] = null;
            $filas[$indiceSinIniciar]['id_etapa_actual'] = null;
            $this->excursiones[$indiceSinIniciar]['iniciada'] = false;
        }

        $this->insertarEnBloques('excursion', $filas);
    }

    // Iniciar Recorrido (CU-11) se puede hacer cualquier día del recorrido, no sólo el de la salida. Para mostrarlo
    // aunque hoy no salga ninguna, entre las que salieron antes de hoy y siguen en curso, la que termina más tarde queda
    // sin iniciar. Un lunes no hay ninguna así: quedan sin iniciar las que salen ese día.
    private function buscarEnCursoQueTerminaMasTarde(): ?int
    {
        $indiceElegido = null;

        foreach ($this->excursiones as $indice => $excursion) {
            $enCurso = $excursion['iniciada'] && $excursion['ultimo_dia']->greaterThanOrEqualTo($this->hoy);

            if ($enCurso && ($indiceElegido === null || $excursion['ultimo_dia']->greaterThan($this->excursiones[$indiceElegido]['ultimo_dia']))) {
                $indiceElegido = $indice;
            }
        }

        return $indiceElegido;
    }

    // Devuelve el inicio del recorrido y la etapa actual. Terminada: el guía la inició el día de la salida, entre las
    // 4:30 y las 6:30, y reportó la etapa final. En curso: iniciada, con la etapa que corresponde a los días que pasaron,
    // sin llegar a la final. La que sale hoy queda sin iniciar, para probar Iniciar Recorrido (CU-11).
    private function calcularAvance(Carbon $fechaSalida, Carbon $ultimoDia, int $dias, array $etapas): array
    {
        if ($fechaSalida->greaterThanOrEqualTo($this->hoy)) {
            return [null, null];
        }

        $inicio = $fechaSalida->copy()->setTime(4, 30)->addMinutes($this->faker->numberBetween(0, 120));

        if ($ultimoDia->lessThan($this->hoy)) {
            return [$inicio, $etapas[count($etapas) - 1]];
        }

        $diasQuePasaron = (int) $fechaSalida->diffInDays($this->hoy);

        return [$inicio, $etapas[intdiv($diasQuePasaron * count($etapas), $dias) - 1]];
    }

    /* -------------------------------- Reservas -------------------------------- */

    // Cada salida nueva se vende hasta su ocupación, nunca más que el cupo. Las canceladas no ocupan lugar
    // (Excursion::sumarPlazasReservadas()). Después se numeran en el orden de fecha_reserva y se insertan con sus
    // integrantes, pagos, comprobantes y devoluciones.
    private function crearReservas(): void
    {
        foreach ($this->excursiones as $indiceExcursion => $excursion) {
            $plazasAVender = $this->calcularPlazasAVender($excursion);
            $plazasVendidas = 0;

            while ($plazasVendidas < $plazasAVender) {
                $cantidadIntegrantes = min($this->elegirConPesos(self::INTEGRANTES_POR_RESERVA), $plazasAVender - $plazasVendidas);
                $reserva = $this->armarReserva($indiceExcursion, $cantidadIntegrantes);

                // Ya no hay fechas en las que se pudiera reservar esta salida.
                if ($reserva === null) {
                    break;
                }

                $this->reservas[] = $reserva;

                if ($reserva['estado'] !== EstadoReserva::Cancelada) {
                    $plazasVendidas += $cantidadIntegrantes;
                }
            }
        }

        usort($this->reservas, fn (array $una, array $otra) => $una['fecha_reserva'] <=> $otra['fecha_reserva']);

        $this->insertarReservas();
    }

    // Ocupación según la temporada del mes de salida; en la seca, casi una de cada tres salidas se agota. Las salidas
    // lejanas todavía se están vendiendo: desde los tres meses, un 5 % menos por cada mes que falta.
    private function calcularPlazasAVender(array $excursion): int
    {
        $mes = $excursion['fecha_salida']->month;

        [$minimo, $maximo] = match (true) {
            in_array($mes, self::MESES_DE_TEMPORADA_SECA, true) => self::OCUPACION_TEMPORADA_SECA,
            in_array($mes, self::MESES_DE_LLUVIA, true) => self::OCUPACION_LLUVIAS,
            default => self::OCUPACION_RESTO_DEL_ANIO,
        };

        $porcentaje = $this->faker->numberBetween($minimo, $maximo);

        if ($maximo === 100 && $this->faker->boolean(30)) {
            $porcentaje = 100;
        }

        $mesesQueFaltan = max(0, (int) $this->hoy->diffInMonths($excursion['fecha_salida']) - config('reserva.meses_anticipacion_minima'));
        $porcentaje *= max(0.3, 1 - 0.05 * $mesesQueFaltan);

        return (int) round($excursion['cupo'] * $porcentaje / 100);
    }

    // La reserva con sus integrantes, sus pagos y, si se canceló con devolución, la devolución. Devuelve null si ya no
    // hay fechas en las que se pudiera reservar la salida.
    private function armarReserva(int $indiceExcursion, int $cantidadIntegrantes): ?array
    {
        $excursion = $this->excursiones[$indiceExcursion];
        $paquete = $this->paquetes[$excursion['paquete']];

        $fechaReserva = $this->elegirFechaReserva($excursion, $paquete);

        if ($fechaReserva === null) {
            return null;
        }

        $fechaLimite = Reserva::calcularFechaLimite($excursion['fecha_salida']);
        $situacion = $this->faker->boolean(self::PORCENTAJE_CANCELADAS)
            ? $this->elegirCancelacion($excursion, $fechaReserva, $fechaLimite)
            : $this->elegirSituacionVigente($excursion, $fechaLimite);

        $cliente = $this->elegirCliente();
        $integrantes = $this->armarIntegrantes($cantidadIntegrantes, $cliente, $paquete['dias'] > 2, $situacion['permisos']);
        $nochesExtra = $this->elegirConPesos(self::NOCHES_EXTRA);
        $nochesExtraAntes = $this->faker->numberBetween(0, $nochesExtra);
        $equiposCamping = count(array_filter(array_column($integrantes, 'equipo_camping')));
        $montoTotal = $paquete['modelo']->calcularMonto($cantidadIntegrantes, $nochesExtra, $equiposCamping);

        // El saldo se paga antes de la fecha límite, antes de cancelar y nunca después de ahora.
        $pagarSaldoHasta = $fechaLimite->copy()->min($this->ahora)->min($situacion['fecha_cancelacion'] ?? $this->ahora);
        $pagos = $this->armarPagos($situacion['pagos'], $montoTotal, $fechaReserva, $pagarSaldoHasta);

        return [
            'excursion' => $indiceExcursion,
            'correo_electronico' => $cliente['correo'],
            'fecha_reserva' => $fechaReserva,
            'estado' => $situacion['estado'],
            'estado_saldo' => $situacion['pagos'] === self::PAGO_SENA ? EstadoSaldo::Adeudado : EstadoSaldo::Abonado,
            'noches_extra_antes' => $nochesExtraAntes,
            'noches_extra_despues' => $nochesExtra - $nochesExtraAntes,
            // Como fijarFechasLimite(): la del saldo, sólo si se pagó la seña. Pagar después el saldo no la borra
            // (actualizarReserva()).
            'fecha_limite_saldo' => $situacion['pagos'] === self::PAGO_TOTAL ? null : $fechaLimite,
            'fecha_limite_confirmacion' => $fechaLimite,
            'integrantes' => $integrantes,
            'pagos' => $pagos,
            'devolucion' => $this->armarDevolucion($situacion, $pagos),
            'causa' => $situacion['causa'] ?? null,
            'valorada' => false,
        ];
    }

    // Entre 11 y 3 meses antes de la salida (la anticipación mínima de CU-14), nunca antes del alta del paquete ni
    // después de ahora.
    private function elegirFechaReserva(array $excursion, array $paquete): ?Carbon
    {
        $desde = $excursion['fecha_salida']->copy()->subMonthsNoOverflow(self::MESES_MAXIMOS_DE_ANTICIPACION);

        if ($paquete['reservas_desde'] !== null) {
            $desde = $desde->max($paquete['reservas_desde']);
        }

        $hasta = $excursion['fecha_salida']->copy()
            ->subMonthsNoOverflow(config('reserva.meses_anticipacion_minima'))
            ->setTime(23, 0)
            ->min($this->ahora);

        return $hasta->lessThan($desde) ? null : $this->momentoEntre($desde, $hasta);
    }

    // El estado de una reserva que no se canceló, según cómo está su salida.
    private function elegirSituacionVigente(array $excursion, Carbon $fechaLimite): array
    {
        // Terminada: el guía reportó la etapa final y las confirmadas pasaron a «Finalizada» (CU-12).
        if ($excursion['ultimo_dia']->lessThan($this->hoy)) {
            return ['estado' => EstadoReserva::Finalizada, 'permisos' => self::PERMISOS_OBTENIDOS, 'pagos' => $this->elegirPagoCompleto()];
        }

        // En curso o con la fecha límite vencida: quedan sólo las confirmadas y pagas. Las demás ya las habría cancelado
        // el Temporizador (CU-22 y CU-26), y con eso mandado correos.
        if ($fechaLimite->lessThan($this->ahora)) {
            return ['estado' => EstadoReserva::Confirmada, 'permisos' => self::PERMISOS_OBTENIDOS, 'pagos' => $this->elegirPagoCompleto()];
        }

        // Con la fecha límite por delante: cuanto más cerca, más reservas con los permisos ya validados (CU-04).
        $diasHastaElLimite = min((int) $this->ahora->diffInDays($fechaLimite), 240);

        if ($this->faker->boolean(85 - intdiv(70 * $diasHastaElLimite, 240))) {
            return [
                'estado' => EstadoReserva::Confirmada,
                'permisos' => self::PERMISOS_OBTENIDOS,
                'pagos' => $this->faker->randomElement([self::PAGO_TOTAL, self::PAGO_SENA, self::PAGO_SENA_Y_SALDO]),
            ];
        }

        // Sin confirmar no se paga el saldo: CU-16 sólo cobra a una reserva «Confirmada».
        if ($this->faker->boolean(self::PORCENTAJE_SIN_PERMISO)) {
            return ['estado' => EstadoReserva::SinPermiso, 'permisos' => self::PERMISOS_CON_RECHAZO, 'pagos' => $this->elegirPrimerPago()];
        }

        return ['estado' => EstadoReserva::Pendiente, 'permisos' => self::PERMISOS_PENDIENTES, 'pagos' => $this->elegirPrimerPago()];
    }

    // Las causas de CU-22 y CU-26 sólo son posibles si el Temporizador ya pasó por la fecha límite: corre a los 5
    // minutos de cada hora, así que cancela a las 00:05 del día siguiente.
    private function elegirCancelacion(array $excursion, Carbon $fechaReserva, Carbon $fechaLimite): array
    {
        $cancelacionAutomatica = $fechaLimite->copy()->addDay()->startOfDay()
            ->addMinutes(config('reserva.minuto_verificacion_confirmaciones_vencidas'));
        $pesoPorCausa = self::PESO_POR_CAUSA;

        if ($cancelacionAutomatica->greaterThan($this->ahora)) {
            unset($pesoPorCausa[self::CAUSA_FALTA_DE_PAGO], $pesoPorCausa[self::CAUSA_FALTA_DE_PERMISOS]);
        }

        $causa = $this->elegirConPesos($pesoPorCausa);

        $cancelacion = match ($causa) {
            // CU-22: estaba «Confirmada» y no pagó el saldo a tiempo. La seña no se devuelve.
            self::CAUSA_FALTA_DE_PAGO => [
                'permisos' => self::PERMISOS_OBTENIDOS,
                'pagos' => self::PAGO_SENA,
                'fecha_cancelacion' => $cancelacionAutomatica,
                'porcentaje_devolucion' => 0,
            ],
            // CU-26: seguía «Pendiente» o «Sin Permiso» al vencer la fecha límite. Reintegro del 100 %.
            self::CAUSA_FALTA_DE_PERMISOS => [
                'permisos' => $this->faker->randomElement([self::PERMISOS_PENDIENTES, self::PERMISOS_CON_RECHAZO]),
                'pagos' => $this->elegirPrimerPago(),
                'fecha_cancelacion' => $cancelacionAutomatica,
                'motivo' => MotivoDevolucion::Reintegro,
                'porcentaje_devolucion' => 1,
            ],
            // CU-17: «Sin Permiso», el cliente pidió el reintegro del 100 % dentro del plazo.
            self::CAUSA_REINTEGRO => [
                'permisos' => self::PERMISOS_CON_RECHAZO,
                'pagos' => $this->elegirPrimerPago(),
                'fecha_cancelacion' => $this->momentoEntre($fechaReserva, $fechaLimite->copy()->min($this->ahora)),
                'motivo' => MotivoDevolucion::Reintegro,
                'porcentaje_devolucion' => 1,
            ],
            self::CAUSA_VOLUNTARIA => $this->elegirCancelacionVoluntaria($excursion, $fechaReserva, $fechaLimite),
        };

        return ['estado' => EstadoReserva::Cancelada, 'causa' => $causa] + $cancelacion;
    }

    // CU-21: el cliente cancela antes de la salida, desde «Pendiente» o «Confirmada». Con más de
    // config('reserva.dias_anticipacion_reembolso') días de anticipación se le reembolsa el 50 % de lo abonado; si no,
    // nada, y no se registra devolución. Es la cuenta de Reserva::calcularReembolso().
    private function elegirCancelacionVoluntaria(array $excursion, Carbon $fechaReserva, Carbon $fechaLimite): array
    {
        $vispera = $excursion['fecha_salida']->copy()->subDay()->setTime(22, 0);
        $fechaCancelacion = $this->momentoEntre($fechaReserva, $vispera->min($this->ahora));
        $diasDeAnticipacion = (int) $fechaCancelacion->copy()->startOfDay()->diffInDays($excursion['fecha_salida']);
        $conReembolso = $diasDeAnticipacion > config('reserva.dias_anticipacion_reembolso');

        // Después de la fecha límite, la única que sigue vigente es la confirmada con el saldo pago.
        if ($fechaCancelacion->greaterThan($fechaLimite)) {
            [$permisos, $pagos] = [self::PERMISOS_OBTENIDOS, $this->elegirPagoCompleto()];
        } elseif ($this->faker->boolean()) {
            [$permisos, $pagos] = [self::PERMISOS_PENDIENTES, $this->elegirPrimerPago()];
        } else {
            [$permisos, $pagos] = [self::PERMISOS_OBTENIDOS, $this->faker->randomElement([self::PAGO_TOTAL, self::PAGO_SENA, self::PAGO_SENA_Y_SALDO])];
        }

        return [
            'permisos' => $permisos,
            'pagos' => $pagos,
            'fecha_cancelacion' => $fechaCancelacion,
            'motivo' => $conReembolso ? MotivoDevolucion::Reembolso : null,
            'porcentaje_devolucion' => $conReembolso ? config('reserva.porcentaje_reembolso') : 0,
        ];
    }

    // Una salida terminada, en curso o con la fecha límite vencida tiene el saldo abonado: el total al reservar, o la
    // seña y después el saldo.
    private function elegirPagoCompleto(): string
    {
        return $this->faker->boolean(self::PORCENTAJE_PAGO_TOTAL) ? self::PAGO_TOTAL : self::PAGO_SENA_Y_SALDO;
    }

    private function elegirPrimerPago(): string
    {
        return $this->faker->boolean(self::PORCENTAJE_PAGO_TOTAL) ? self::PAGO_TOTAL : self::PAGO_SENA;
    }

    // Algunos clientes vuelven: repiten el correo, hasta en tres reservas. El titular es el primer integrante.
    private function elegirCliente(): array
    {
        if ($this->clientes !== [] && $this->faker->boolean(self::PORCENTAJE_CLIENTES_QUE_VUELVEN)) {
            $correo = $this->faker->randomElement(array_keys($this->clientes));

            if ($this->clientes[$correo]['reservas'] < self::RESERVAS_MAXIMAS_POR_CLIENTE) {
                $this->clientes[$correo]['reservas']++;

                return $this->clientes[$correo];
            }
        }

        $nombre = $this->faker->randomElement(self::NOMBRES);
        $apellido = $this->faker->randomElement(self::APELLIDOS);
        $usuario = Str::slug($nombre.' '.$apellido, '.');
        $correo = $usuario.'@'.$this->faker->randomElement(self::DOMINIOS_DE_EJEMPLO);

        while (isset($this->clientes[$correo])) {
            $correo = $usuario.$this->faker->numberBetween(1, 999).'@'.$this->faker->randomElement(self::DOMINIOS_DE_EJEMPLO);
        }

        $this->clientes[$correo] = [
            'correo' => $correo,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'documento_pasaporte' => $this->generarDocumento(),
            'reservas' => 1,
        ];

        return $this->clientes[$correo];
    }

    // Muchos viajan en familia y comparten el apellido del titular. El documento no se repite dentro de la reserva. El
    // equipo de camping, sólo en los recorridos con campings.
    private function armarIntegrantes(int $cantidad, array $cliente, bool $conCampings, string $permisos): array
    {
        $integrantes = [];
        $documentos = [$cliente['documento_pasaporte']];

        for ($numero = 1; $numero <= $cantidad; $numero++) {
            if ($numero === 1) {
                [$nombre, $apellido, $documento] = [$cliente['nombre'], $cliente['apellido'], $cliente['documento_pasaporte']];
            } else {
                $nombre = $this->faker->randomElement(self::NOMBRES);
                $apellido = $this->faker->boolean() ? $cliente['apellido'] : $this->faker->randomElement(self::APELLIDOS);

                do {
                    $documento = $this->generarDocumento();
                } while (in_array($documento, $documentos, true));

                $documentos[] = $documento;
            }

            $integrantes[] = [
                'nombre' => $nombre,
                'apellido' => $apellido,
                'documento_pasaporte' => $documento,
                'equipo_camping' => $conCampings && $this->faker->boolean(33),
                'estado_permiso' => match ($permisos) {
                    self::PERMISOS_PENDIENTES => EstadoPermiso::Pendiente,
                    self::PERMISOS_OBTENIDOS => EstadoPermiso::Obtenido,
                    self::PERMISOS_CON_RECHAZO => $this->faker->boolean(35) ? EstadoPermiso::NoObtenido : EstadoPermiso::Obtenido,
                },
            ];
        }

        // «Sin Permiso» quiere decir que al menos un permiso se rechazó (CU-04).
        if ($permisos === self::PERMISOS_CON_RECHAZO) {
            $integrantes[$this->faker->numberBetween(0, $cantidad - 1)]['estado_permiso'] = EstadoPermiso::NoObtenido;
        }

        return $integrantes;
    }

    private function generarDocumento(): string
    {
        return strtoupper($this->faker->bothify($this->faker->randomElement(self::FORMATOS_DOCUMENTO)));
    }

    // El primer pago es en fecha_reserva. La seña es Pago::calcularMontoAPagar() y el saldo, lo que falta del total.
    private function armarPagos(string $formaDePago, float $montoTotal, Carbon $fechaReserva, Carbon $pagarSaldoHasta): array
    {
        if ($formaDePago === self::PAGO_TOTAL) {
            return [$this->armarPago(TipoPago::Total, $montoTotal, $fechaReserva)];
        }

        $sena = Pago::calcularMontoAPagar($montoTotal, TipoPago::Sena);
        $pagos = [$this->armarPago(TipoPago::Sena, $sena, $fechaReserva)];

        if ($formaDePago === self::PAGO_SENA_Y_SALDO) {
            $pagos[] = $this->armarPago(TipoPago::Saldo, round($montoTotal - $sena, 2), $this->momentoEntre($fechaReserva, $pagarSaldoHasta));
        }

        return $pagos;
    }

    private function armarPago(TipoPago $tipoPago, float $monto, Carbon $momento): array
    {
        return ['tipo_pago' => $tipoPago, 'monto' => $monto, 'momento' => $momento, 'medio_pago' => $this->elegirConPesos(self::MEDIOS_DE_PAGO)];
    }

    // Sobre la suma de todos los pagos de la reserva, con la fecha de la cancelación. Sin devolución, no hay fila.
    private function armarDevolucion(array $situacion, array $pagos): ?array
    {
        if (($situacion['porcentaje_devolucion'] ?? 0) == 0) {
            return null;
        }

        return [
            'fecha' => $situacion['fecha_cancelacion']->toDateString(),
            'monto' => round(array_sum(array_column($pagos, 'monto')) * $situacion['porcentaje_devolucion'], 2),
            'motivo' => $situacion['motivo'],
        ];
    }

    // Asigna las claves y los números, y escribe las filas. El número sigue al más alto que ya existe, como
    // generarNumeroReserva(), salteando los que tienen resto 10. Los pagos se numeran en el orden en que se hicieron,
    // y cada uno lleva su comprobante, numerado como en Comprobante::generarComprobante().
    private function insertarReservas(): void
    {
        $secuencial = (int) Str::before((string) DB::table('reserva')->max('numero_reserva'), '-');
        $idReserva = $this->siguienteId('reserva', 'id_reserva');
        $idExcursionista = $this->siguienteId('excursionista', 'id_excursionista');
        $idDevolucion = $this->siguienteId('devolucion', 'id_devolucion');
        $filasReserva = [];
        $filasExcursionista = [];
        $filasDevolucion = [];
        $pagos = [];

        foreach ($this->reservas as $indice => $reserva) {
            do {
                $secuencial++;
            } while (Reserva::calcularDigitoVerificador($secuencial) === 10);

            $numeroReserva = str_pad((string) $secuencial, self::CIFRAS_NUMERO_RESERVA, '0', STR_PAD_LEFT)
                .'-'.Reserva::calcularDigitoVerificador($secuencial);
            $this->reservas[$indice]['id_reserva'] = $idReserva;
            $this->reservas[$indice]['numero_reserva'] = $numeroReserva;

            $filasReserva[] = [
                'id_reserva' => $idReserva,
                'id_excursion' => $this->excursiones[$reserva['excursion']]['id_excursion'],
                'numero_reserva' => $numeroReserva,
                'correo_electronico' => $reserva['correo_electronico'],
                'fecha_reserva' => $reserva['fecha_reserva']->toDateTimeString(),
                'estado' => $reserva['estado']->value,
                'estado_saldo' => $reserva['estado_saldo']->value,
                'noches_extra_antes' => $reserva['noches_extra_antes'],
                'noches_extra_despues' => $reserva['noches_extra_despues'],
                'fecha_limite_saldo' => $reserva['fecha_limite_saldo']?->toDateTimeString(),
                'fecha_limite_confirmacion' => $reserva['fecha_limite_confirmacion']->toDateTimeString(),
            ];

            foreach ($reserva['integrantes'] as $integrante) {
                $filasExcursionista[] = ['id_excursionista' => $idExcursionista++, 'id_reserva' => $idReserva, 'estado_permiso' => $integrante['estado_permiso']->value]
                    + Arr::only($integrante, ['nombre', 'apellido', 'documento_pasaporte', 'equipo_camping']);
            }

            foreach ($reserva['pagos'] as $pago) {
                $pagos[] = ['id_reserva' => $idReserva] + $pago;
            }

            if ($reserva['devolucion'] !== null) {
                $filasDevolucion[] = [
                    'id_devolucion' => $idDevolucion++,
                    'id_reserva' => $idReserva,
                    'fecha' => $reserva['devolucion']['fecha'],
                    'monto' => $reserva['devolucion']['monto'],
                    'motivo' => $reserva['devolucion']['motivo']->value,
                ];
            }

            $idReserva++;
        }

        usort($pagos, fn (array $uno, array $otro) => $uno['momento'] <=> $otro['momento']);

        $idPago = $this->siguienteId('pago', 'id_pago');
        $idComprobante = $this->siguienteId('comprobante', 'id_comprobante');
        $filasPago = [];
        $filasComprobante = [];

        foreach ($pagos as $pago) {
            $filasPago[] = [
                'id_pago' => $idPago,
                'id_reserva' => $pago['id_reserva'],
                'fecha' => $pago['momento']->toDateString(),
                'monto' => $pago['monto'],
                'tipo_pago' => $pago['tipo_pago']->value,
                'medio_pago' => $pago['medio_pago'],
            ];
            $filasComprobante[] = [
                'id_comprobante' => $idComprobante++,
                'id_pago' => $idPago,
                'numero_comprobante' => Comprobante::PREFIJO_NUMERO.'-'.str_pad((string) $idPago, self::CIFRAS_NUMERO_COMPROBANTE, '0', STR_PAD_LEFT),
                'fecha_emision' => $pago['momento']->toDateTimeString(),
            ];
            $idPago++;
        }

        $this->insertarEnBloques('reserva', $filasReserva);
        $this->insertarEnBloques('excursionista', $filasExcursionista);
        $this->insertarEnBloques('pago', $filasPago);
        $this->insertarEnBloques('comprobante', $filasComprobante);
        $this->insertarEnBloques('devolucion', $filasDevolucion);
    }

    /* ------------------------------ Valoraciones ------------------------------ */

    // Una parte de las finalizadas, de 1 a 20 días después de terminado el recorrido y nunca después de ahora.
    private function crearValoraciones(): void
    {
        $valoraciones = [];

        foreach ($this->reservas as $indice => $reserva) {
            if ($reserva['estado'] !== EstadoReserva::Finalizada || ! $this->faker->boolean(self::PORCENTAJE_VALORADAS)) {
                continue;
            }

            $excursion = $this->excursiones[$reserva['excursion']];
            $desde = $excursion['ultimo_dia']->copy()->addDay()->setTime(9, 0);
            $hasta = $excursion['ultimo_dia']->copy()->addDays(20)->setTime(22, 0)->min($this->ahora);

            if ($hasta->lessThan($desde)) {
                continue;
            }

            $valoraciones[] = $this->armarValoracion($reserva, $excursion, $this->momentoEntre($desde, $hasta));
            $this->reservas[$indice]['valorada'] = true;
        }

        usort($valoraciones, fn (array $una, array $otra) => $una['fecha'] <=> $otra['fecha']);

        $idValoracion = $this->siguienteId('valoracion', 'id_valoracion');
        $filasValoracion = [];
        $filasDetalle = [];

        foreach ($valoraciones as $valoracion) {
            $filasValoracion[] = [
                'id_valoracion' => $idValoracion,
                'id_reserva' => $valoracion['id_reserva'],
                'fecha' => $valoracion['fecha']->toDateTimeString(),
                'puntaje_experiencia_general' => $valoracion['puntaje_experiencia_general'],
                'comentario' => $valoracion['comentario'],
            ];

            foreach ($valoracion['puntajes'] as $categoria => $puntaje) {
                $filasDetalle[] = ['id_valoracion' => $idValoracion, 'categoria' => $categoria, 'puntaje' => $puntaje];
            }

            $idValoracion++;
        }

        $this->insertarEnBloques('valoracion', $filasValoracion);
        $this->insertarEnBloques('detalle_valoracion', $filasDetalle);
    }

    // Las categorías de Valoracion::getCategoriasAValorar(): todas, y el equipo de camping sólo si alguien lo llevó. Los
    // guías con quejas tienen puntajes bajos en «Guía»; el paquete con quejas del hotel, en «Hotel»; y en los meses de
    // lluvia bajan el camping, los transportes y la experiencia general.
    private function armarValoracion(array $reserva, array $excursion, Carbon $fecha): array
    {
        $conLluvia = in_array($excursion['fecha_salida']->month, self::MESES_DE_LLUVIA, true);
        $llevaronEquipo = in_array(true, array_column($reserva['integrantes'], 'equipo_camping'), true);
        $guiaConQuejas = $this->guias[$excursion['id_guia']]['con_quejas'];
        $hotelConQuejas = $this->paquetes[$excursion['paquete']]['quejas_hotel'];
        $puntajes = [];

        foreach (CategoriaValoracion::cases() as $categoria) {
            if ($categoria === CategoriaValoracion::EquipoDeCamping && ! $llevaronEquipo) {
                continue;
            }

            $puntajes[$categoria->value] = $this->elegirConPesos(match (true) {
                $categoria === CategoriaValoracion::Guia && $guiaConQuejas => self::PUNTAJES_CON_QUEJAS,
                $categoria === CategoriaValoracion::Hotel && $hotelConQuejas => self::PUNTAJES_CON_QUEJAS,
                $conLluvia && in_array($categoria, [CategoriaValoracion::CampingDeEtapa, CategoriaValoracion::TransporteEnBus, CategoriaValoracion::TransporteFerroviario], true) => self::PUNTAJES_REGULARES,
                default => self::PUNTAJES_HABITUALES,
            });
        }

        $conQuejas = $puntajes[CategoriaValoracion::Guia->value] <= 2 || $puntajes[CategoriaValoracion::Hotel->value] <= 2;
        $experienciaGeneral = $this->elegirConPesos($conLluvia || $conQuejas ? self::PUNTAJES_REGULARES : self::PUNTAJES_HABITUALES);

        return [
            'id_reserva' => $reserva['id_reserva'],
            'fecha' => $fecha,
            'puntaje_experiencia_general' => $experienciaGeneral,
            'comentario' => $this->elegirComentario($puntajes, $experienciaGeneral, $conLluvia),
            'puntajes' => $puntajes,
        ];
    }

    // El comentario va con los puntajes: primero las quejas puntuales, después el tono de la experiencia general.
    private function elegirComentario(array $puntajes, int $experienciaGeneral, bool $conLluvia): ?string
    {
        if ($this->faker->boolean(self::PORCENTAJE_SIN_COMENTARIO)) {
            return null;
        }

        $comentarios = match (true) {
            $puntajes[CategoriaValoracion::Guia->value] <= 2 => self::COMENTARIOS_SOBRE_EL_GUIA,
            $puntajes[CategoriaValoracion::Hotel->value] <= 2 => self::COMENTARIOS_SOBRE_EL_HOTEL,
            $conLluvia && $experienciaGeneral <= 3 => self::COMENTARIOS_CON_LLUVIA,
            $experienciaGeneral >= 4 => self::COMENTARIOS_POSITIVOS,
            $experienciaGeneral === 3 => self::COMENTARIOS_REGULARES,
            default => self::COMENTARIOS_NEGATIVOS,
        };

        $comentario = $this->faker->randomElement($comentarios);

        if ($experienciaGeneral >= 4 && $this->faker->boolean()) {
            $comentario .= ' '.$this->faker->randomElement(self::DETALLES_POSITIVOS);
        }

        return $comentario;
    }

    /* -------------------------------- Auxiliares ------------------------------ */

    // Recibe clave => peso y devuelve una clave: una de peso 4 sale el doble de veces que una de peso 2.
    private function elegirConPesos(array $pesoPorClave): int|string
    {
        $sorteo = $this->faker->numberBetween(1, array_sum($pesoPorClave));

        foreach ($pesoPorClave as $clave => $peso) {
            $sorteo -= $peso;

            if ($sorteo <= 0) {
                return $clave;
            }
        }

        return array_key_last($pesoPorClave);
    }

    // Se sortea una fracción del intervalo y no los segundos: así cada sorteo usa un solo número de la semilla aunque el
    // intervalo cambie con la hora en que se corre, y los demás datos no se corren.
    private function momentoEntre(Carbon $desde, Carbon $hasta): Carbon
    {
        $segundos = max(0, (int) $desde->diffInSeconds($hasta));

        return $desde->copy()->addSeconds((int) round($segundos * $this->faker->randomFloat(6, 0, 1)));
    }

    // Las claves se asignan acá para insertar de a bloques y relacionar las filas sin volver a leerlas.
    private function siguienteId(string $tabla, string $clave): int
    {
        return (int) DB::table($tabla)->max($clave) + 1;
    }

    private function insertarEnBloques(string $tabla, array $filas): void
    {
        foreach (array_chunk($filas, self::FILAS_POR_BLOQUE) as $bloque) {
            DB::table($tabla)->insert($bloque);
        }
    }

    // Como las claves se asignaron a mano, en PostgreSQL la secuencia de cada tabla tiene que seguir después de la más
    // alta: si no, la próxima reserva o el próximo pago desde la aplicación chocaría con una clave ya usada.
    private function ajustarSecuencias(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CLAVES_AUTOINCREMENTALES as $tabla => $clave) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$tabla}', '{$clave}'), (SELECT MAX({$clave}) FROM {$tabla}))");
        }
    }

    /* --------------------------------- Resumen -------------------------------- */

    private function mostrarResumen(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->table(['Tabla', 'Filas'], array_map(fn (string $tabla) => [$tabla, DB::table($tabla)->count()], self::TABLAS));

        $this->command->table(['Estado', 'Reservas'], DB::table('reserva')
            ->select('estado', DB::raw('count(*) as cantidad'))
            ->groupBy('estado')
            ->orderBy('estado')
            ->get()
            ->map(fn ($fila) => [$fila->estado, $fila->cantidad])
            ->all());

        $this->command->table(['Caso', 'Número', 'Correo', 'Paquete', 'Salida'], $this->armarMuestras());
    }

    // Una reserva de cada caso para probar a mano, la más reciente de cada uno. Una excursión iniciada para Reportar
    // Etapa (si hay) y una sin iniciar para Iniciar Recorrido, con el correo de su guía. Y un paquete activo sin reservas
    // activas, el único que se puede modificar o dar de baja.
    private function armarMuestras(): array
    {
        $casos = [
            'Pendiente' => fn (array $reserva) => $reserva['estado'] === EstadoReserva::Pendiente,
            'Confirmada con saldo adeudado' => fn (array $reserva) => $reserva['estado'] === EstadoReserva::Confirmada && $reserva['estado_saldo'] === EstadoSaldo::Adeudado,
            'Confirmada abonada' => fn (array $reserva) => $reserva['estado'] === EstadoReserva::Confirmada && $reserva['estado_saldo'] === EstadoSaldo::Abonado,
            'Sin Permiso dentro del plazo' => fn (array $reserva) => $reserva['estado'] === EstadoReserva::SinPermiso,
            'Finalizada sin valorar' => fn (array $reserva) => $reserva['estado'] === EstadoReserva::Finalizada && ! $reserva['valorada'],
            'Finalizada valorada' => fn (array $reserva) => $reserva['valorada'],
            'Cancelada por '.self::CAUSA_FALTA_DE_PAGO => fn (array $reserva) => $reserva['causa'] === self::CAUSA_FALTA_DE_PAGO,
            'Cancelada por '.self::CAUSA_FALTA_DE_PERMISOS => fn (array $reserva) => $reserva['causa'] === self::CAUSA_FALTA_DE_PERMISOS,
            'Cancelada por '.self::CAUSA_REINTEGRO => fn (array $reserva) => $reserva['causa'] === self::CAUSA_REINTEGRO,
            'Cancelada por '.self::CAUSA_VOLUNTARIA.', con reembolso' => fn (array $reserva) => $reserva['causa'] === self::CAUSA_VOLUNTARIA && $reserva['devolucion'] !== null,
            'Cancelada por '.self::CAUSA_VOLUNTARIA.', sin devolución' => fn (array $reserva) => $reserva['causa'] === self::CAUSA_VOLUNTARIA && $reserva['devolucion'] === null,
        ];
        $muestras = [];
        $masRecientesPrimero = array_reverse($this->reservas);

        foreach ($casos as $caso => $cumpleElCaso) {
            $reserva = Arr::first($masRecientesPrimero, $cumpleElCaso);

            if ($reserva !== null) {
                $excursion = $this->excursiones[$reserva['excursion']];
                $muestras[] = [$caso, $reserva['numero_reserva'], $reserva['correo_electronico'], $this->paquetes[$excursion['paquete']]['nombre'], $excursion['fecha_salida']->format('d/m/Y')];
            }
        }

        $excursionPorCaso = [
            'Excursión en curso (Reportar Etapa)' => Arr::first($this->excursiones, fn (array $excursion) => $excursion['iniciada']
                && $excursion['ultimo_dia']->greaterThanOrEqualTo($this->hoy)),
            'Excursión sin iniciar (Iniciar Recorrido)' => Arr::first($this->excursiones, fn (array $excursion) => ! $excursion['iniciada']
                && $excursion['fecha_salida']->lessThanOrEqualTo($this->hoy)
                && $excursion['ultimo_dia']->greaterThanOrEqualTo($this->hoy)),
        ];

        foreach ($excursionPorCaso as $caso => $excursion) {
            if ($excursion !== null) {
                $muestras[] = [
                    $caso,
                    '—',
                    $this->guias[$excursion['id_guia']]['correo'],
                    $this->paquetes[$excursion['paquete']]['nombre'],
                    $excursion['fecha_salida']->format('d/m/Y'),
                ];
            }
        }

        $paqueteSinReservasActivas = Paquete::buscarActivos()->orderBy('id_paquete')->get()
            ->first(fn (Paquete $paquete) => ! $paquete->tieneReservasActivas());

        if ($paqueteSinReservasActivas !== null) {
            $muestras[] = ['Paquete sin reservas activas (Modificar y Dar de Baja)', '—', '—', $paqueteSinReservasActivas->nombre, '—'];
        }

        return $muestras;
    }
}
