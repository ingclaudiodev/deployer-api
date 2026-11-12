<?php

namespace App\Enums;

/**
 * Where a project is in the deploy flow. The values are the contract with the
 * client (`EstadoProyecto` in its `proyecto.types.ts`).
 */
enum ProjectStatus: string
{
    case NotDeployed = 'sin_despliegue';
    case PendingApproval = 'pendiente_aprobacion';
    case Building = 'construyendo';
    case Deploying = 'desplegando';
    case Live = 'en_vivo';
    case Stopped = 'detenido';
    case Failed = 'fallido';
}
