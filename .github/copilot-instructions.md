# Instrucciones para Copilot

## Mensajes de commit

Escribí los commits en español y como los escribiría a mano alguien del grupo, no como un changelog formal.

- El resumen empieza con el prefijo que pide `CONTRIBUTING.md` y después dice qué hace el cambio:
  - `CU-NN:` si el cambio es de un caso de uso (por ejemplo `CU-14:`).
  - `Base:` para la base compartida (migraciones, modelos, seeders).
  - `Arreglo:` para un arreglo.
  - `Docs:` para documentación.
- Si no se puede saber el número del caso de uso, no lo inventes: dejá el resumen sin prefijo.
- Después del prefijo va una frase corta y directa, en minúscula y sin punto final. Ejemplos del estilo:
  - "CU-15: registra el pago y libera el cupo retenido"
  - "Base: migración y modelo de Excursión"
  - "Arreglo: el cupo disponible no descontaba las plazas retenidas"
  - "Arreglo: migra a enumerados en español"
- Lenguaje simple y cotidiano. Evitá frases como "se implementa", "se refactoriza la lógica correspondiente" o
  "mejoras varias".
- Sin prefijos en inglés (feat:, fix:, chore:) ni emojis.
- La descripción es opcional: si el cambio se entiende con el resumen, dejala vacía.
- Si hace falta descripción, que sean una o dos oraciones contando qué cambió y por qué, en tono normal, sin viñetas ni
  títulos.
- No listes archivo por archivo ni repitas lo que ya dice el resumen.
