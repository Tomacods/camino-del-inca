<?php

namespace Database\Seeders;

use App\Models\Guia;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class GuiaSeeder extends Seeder
{
    // Cada cuenta con rol Guía necesita su fila en guia. Los nombres son de prueba (Faker, en el idioma de APP_FAKER_LOCALE).
    public function run(): void
    {
        $usuariosGuia = Usuario::where('rol', Usuario::ROL_GUIA)->get();

        foreach ($usuariosGuia as $usuario) {
            Guia::firstOrCreate(
                ['id_usuario' => $usuario->id_usuario],
                ['nombre' => fake()->firstName(), 'apellido' => fake()->lastName()],
            );
        }
    }
}
