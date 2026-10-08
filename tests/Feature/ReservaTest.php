<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservaTest extends TestCase
{
    use RefreshDatabase;

    private ?Excursion $excursion = null;

    public function test_acepta_ninguna_noche_extra(): void
    {
        $this->assertTrue(Reserva::validarNochesExtra(0, 0));
    }

    public function test_acepta_una_noche_antes_y_una_despues(): void
    {
        $this->assertTrue(Reserva::validarNochesExtra(1, 1));
    }

    public function test_acepta_las_dos_noches_antes_del_recorrido(): void
    {
        $this->assertTrue(Reserva::validarNochesExtra(2, 0));
    }

    public function test_rechaza_mas_de_dos_noches_en_total(): void
    {
        $this->assertFalse(Reserva::validarNochesExtra(2, 1));
    }

    public function test_rechaza_noches_negativas(): void
    {
        $this->assertFalse(Reserva::validarNochesExtra(-1, 0));
        $this->assertFalse(Reserva::validarNochesExtra(0, -1));
        $this->assertFalse(Reserva::validarNochesExtra(-1, 2));
    }

    public function test_el_maximo_de_noches_sale_de_la_configuracion(): void
    {
        config(['reserva.maximo_noches_extra' => 3]);

        $this->assertTrue(Reserva::validarNochesExtra(2, 1));
        $this->assertFalse(Reserva::validarNochesExtra(2, 2));
    }

    public function test_calcula_el_monto_total_de_una_reserva_guardada(): void
    {
        $reserva = $this->crearReservaDelPrototipo();

        $this->assertSame(2700.0, $reserva->calcularMontoTotal());
    }

    public function test_con_la_sena_pagada_el_saldo_es_la_otra_mitad(): void
    {
        $reserva = $this->crearReservaDelPrototipo();
        Pago::create([
            'id_reserva' => $reserva->id_reserva,
            'fecha' => '2026-09-30',
            'monto' => 1350,
            'tipo_pago' => TipoPago::Sena,
            'medio_pago' => 'Tarjeta de crédito',
        ]);

        $this->assertSame(1350.0, $reserva->calcularSaldoPendiente());
    }

    public function test_calcula_el_digito_verificador_con_modulo_11(): void
    {
        $digitos = [118 => 1, 119 => 3, 121 => 1, 122 => 3, 123 => 5, 124 => 7, 125 => 9, 126 => 0];

        foreach ($digitos as $numero => $digito) {
            $this->assertSame($digito, Reserva::calcularDigitoVerificador($numero), "Dígito de {$numero}");
        }
    }

    public function test_el_digito_verificador_puede_dar_10(): void
    {
        $this->assertSame(10, Reserva::calcularDigitoVerificador(120));
    }

    public function test_sin_reservas_el_primer_numero_es_el_1(): void
    {
        $this->assertSame('000001-2', Reserva::generarNumeroReserva());
    }

    public function test_el_numero_sigue_al_ultimo_emitido(): void
    {
        $this->crearReserva('000124-7');
        $this->crearReserva('000125-9');

        $this->assertSame('000126-0', Reserva::generarNumeroReserva());
    }

    public function test_saltea_los_numeros_con_resto_10(): void
    {
        $this->crearReserva('000119-3');

        $this->assertSame('000121-1', Reserva::generarNumeroReserva());
    }

    public function test_con_la_sena_las_dos_fechas_limite_vencen_un_mes_antes_de_la_salida(): void
    {
        $reserva = new Reserva(['estado_saldo' => EstadoSaldo::Adeudado]);

        $reserva->fijarFechasLimite('2027-01-18');

        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
    }

    public function test_con_el_total_abonado_no_hay_fecha_limite_de_saldo(): void
    {
        $reserva = new Reserva(['estado_saldo' => EstadoSaldo::Abonado]);

        $reserva->fijarFechasLimite('2027-01-18');

        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertNull($reserva->fecha_limite_saldo);
    }

    public function test_la_fecha_limite_no_se_pasa_al_mes_siguiente(): void
    {
        $reserva = new Reserva(['estado_saldo' => EstadoSaldo::Adeudado]);

        // Febrero de 2027 no tiene 29: queda en el último día del mes.
        $reserva->fijarFechasLimite('2027-03-29');

        $this->assertSame('2027-02-28 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-28 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
    }

    public function test_el_plazo_de_las_fechas_limite_sale_de_la_configuracion(): void
    {
        config(['reserva.meses_anticipacion_fechas_limite' => 2]);
        $reserva = new Reserva(['estado_saldo' => EstadoSaldo::Adeudado]);

        $reserva->fijarFechasLimite('2027-01-18');

        $this->assertSame('2026-11-18 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
    }

    // La del prototipo: 3 integrantes, 2 con equipo de camping y una noche extra antes y una después.
    private function crearReservaDelPrototipo(): Reserva
    {
        $reserva = $this->crearReserva('000124-7');

        foreach ([['Lucía', 'AAG604118', true], ['Martín', 'AAG604119', true], ['Sofía', 'AAG611025', false]] as [$nombre, $documento, $equipo]) {
            $reserva->excursionistas()->create([
                'nombre' => $nombre,
                'apellido' => 'Fernández',
                'documento_pasaporte' => $documento,
                'equipo_camping' => $equipo,
                'estado_permiso' => EstadoPermiso::Pendiente,
            ]);
        }

        return $reserva;
    }

    private function crearReserva(string $numeroReserva): Reserva
    {
        $this->excursion ??= $this->crearExcursion();

        return Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'lucia.fernandez@mail.com',
            'fecha_reserva' => '2026-09-30 10:30:00',
            'estado' => EstadoReserva::Pendiente,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 1,
            'fecha_limite_saldo' => '2026-12-18 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-18 23:59:59',
        ]);
    }

    private function crearExcursion(): Excursion
    {
        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
    }
}
