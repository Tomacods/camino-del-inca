// Recorrido de la portada (componente hitos-del-camino): al bajar, la foto de fondo pasa de un hito al siguiente y se
// acerca de a poco. Es la idea del paseo de midudev/mcp-higgsfield-landing (src/scripts/world.ts), con fotos fijas en
// vez de video y sin GSAP: alcanza con escuchar el scroll.
const escenario = document.querySelector('[data-escenario]');

if (escenario) {
    const paradas = [...document.querySelectorAll('[data-parada]')];
    const fotos = [...escenario.querySelectorAll('[data-foto]')];
    const enlaces = [...escenario.querySelectorAll('[data-ir-a]')];
    const relleno = escenario.querySelector('[data-relleno]');
    const sinMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const limitar = (valor) => Math.min(1, Math.max(0, valor));

    // Dónde está el visitante: 0 hasta que la primera parada llega al borde de arriba de la pantalla, 1 cuando llega la
    // segunda, 2.5 a mitad de camino entre la tercera y la cuarta.
    const posicionEnElRecorrido = () => {
        for (let indice = 0; indice < paradas.length; indice++) {
            const { top, height } = paradas[indice].getBoundingClientRect();

            if (top + height > 0) {
                return indice + limitar(-top / height);
            }
        }

        return paradas.length;
    };

    const dibujar = () => {
        const posicion = posicionEnElRecorrido();
        const actual = Math.round(posicion);

        // Cada foto aparece en la segunda mitad del tramo anterior y, desde su parada, se acerca un 6 % hasta la siguiente.
        // De las leyendas se ve sólo la de la foto actual, para que no se encimen mientras una foto pasa a la otra.
        fotos.forEach((foto, indice) => {
            foto.style.opacity = indice === 0 ? 1 : limitar((posicion - indice + 0.5) / 0.5);
            foto.querySelector('p').style.opacity = indice === actual ? 1 : 0;
            // Una foto tapada del todo por la siguiente no se ve: ocultarla le ahorra al navegador dibujarla.
            foto.style.visibility = posicion >= indice + 1 ? 'hidden' : '';

            if (!sinMovimiento) {
                foto.querySelector('img').style.scale = 1 + 0.06 * limitar(posicion - indice);
            }
        });

        enlaces.forEach((enlace, indice) => {
            if (indice === actual) {
                enlace.setAttribute('aria-current', 'step');
            } else {
                enlace.removeAttribute('aria-current');
            }
        });

        if (relleno) {
            relleno.style.scale = `1 ${limitar(posicion / (enlaces.length - 1))}`;
        }
    };

    // Se dibuja a lo sumo una vez por cuadro, aunque el scroll avise muchas veces.
    let dibujoPendiente = false;

    const pedirDibujo = () => {
        if (dibujoPendiente) {
            return;
        }

        dibujoPendiente = true;
        requestAnimationFrame(() => {
            dibujoPendiente = false;
            dibujar();
        });
    };

    window.addEventListener('scroll', pedirDibujo, { passive: true });
    window.addEventListener('resize', pedirDibujo);
    // Abrir o cerrar una tarjeta cambia el alto de su parada sin mover el scroll.
    document.addEventListener('toggle', pedirDibujo, true);
    dibujar();
}
