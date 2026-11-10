/*
 * anclas.js — interacción con objetos 3D mediante HTML superpuesto (TECH_PLAN §6.5).
 *
 * En lugar de raycasting, la posición del objeto se proyecta a pantalla en cada frame y un
 * <button> real se coloca encima: foco por teclado, Enter/Espacio, lector de pantalla y
 * tooltip seleccionable sin matemática de rayos. En modo texto la capa se oculta y la
 * información vive en el panel de la escena.
 */
(function (SARD) {
    'use strict';

    const anclas = new Set();
    let vector = null;
    let baja = null;

    function actualizar() {
        const { camara, ancho, alto } = SARD.Motor;
        anclas.forEach((a) => {
            a.objeto.getWorldPosition(vector);
            vector.y += a.desplazamientoY;
            vector.project(camara);
            const visible = vector.z < 1 && Math.abs(vector.x) <= 1.05 && Math.abs(vector.y) <= 1.05 && a.objeto.visible;
            const x = (vector.x * 0.5 + 0.5) * ancho;
            const y = (-vector.y * 0.5 + 0.5) * alto;
            a.boton.style.transform = `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px) translate(-50%, -50%)`;
            a.boton.style.visibility = visible ? 'visible' : 'hidden';
        });
    }

    const Anclas = {
        /**
         * Ancla un botón HTML a un objeto 3D.
         * @param {THREE.Object3D} objeto
         * @param {HTMLElement} boton   ya con aria-label descriptivo
         * @param {{desplazamientoY?: number}} [opciones]
         * @returns {() => void} función para soltar el ancla
         */
        anclar(objeto, boton, opciones = {}) {
            if (!vector) { vector = new THREE.Vector3(); }
            boton.classList.add('ancla');
            document.getElementById('anclas').append(boton);
            const ancla = { objeto, boton, desplazamientoY: opciones.desplazamientoY || 0 };
            anclas.add(ancla);
            if (!baja) { baja = SARD.Motor.alFrame(actualizar); }
            return () => {
                anclas.delete(ancla);
                boton.remove();
                if (anclas.size === 0 && baja) { baja(); baja = null; }
            };
        },

        cantidad: () => anclas.size,
    };

    SARD.Anclas = Anclas;
})(window.SARD = window.SARD || {});
