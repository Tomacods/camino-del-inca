<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UsuarioSeeder::class,
            GuiaSeeder::class,
            RecorridoSeeder::class,
            ServicioSeeder::class,
            PaqueteSeeder::class,
            ExcursionSeeder::class,
            ReservaSeeder::class,
            PagoSeeder::class,
            DevolucionSeeder::class,
            ValoracionSeeder::class,
        ]);

        // Miles de reservas para la presentación: no corren en las pruebas, que llaman a $this->seed() y usan las
        // reservas de los seeders de arriba.
        if (! app()->runningUnitTests()) {
            $this->call(DatosDeVolumenSeeder::class);
        }
    }
}
