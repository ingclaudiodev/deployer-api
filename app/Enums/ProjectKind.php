<?php

namespace App\Enums;

/**
 * What a project runs as. The values are the contract with the client
 * (`TipoProyecto` in its `proyecto.types.ts`).
 */
enum ProjectKind: string
{
    /** Answers HTTP requests and scales with them. */
    case Service = 'servicio';

    /** Runs to completion and ends; takes no requests. */
    case Job = 'tarea';

    /** A fixed number of instances always on; takes no requests. */
    case WorkerPool = 'trabajadores';

    /** One dedicated instance, without autoscaling. */
    case Instance = 'instancia';
}
