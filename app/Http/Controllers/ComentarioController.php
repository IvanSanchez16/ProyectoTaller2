<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalificarRequest;
use App\Models\Calificacion;
use App\Models\Comentario;
use Illuminate\Support\Facades\Auth;

class ComentarioController extends Controller
{
    public function calificar(CalificarRequest $request,Comentario $comentario) {
        $user = Auth::user();
        if ($user->nocontrol == $comentario->com_user)
            return response()->json([
                "Error" => 'No puedes votar tus propios posts o comentarios'
            ],401);

        $calificacion = $request->get('voto');
        $calificacion = $calificacion == "1";
        $califico = Calificacion::where('cal_id',$comentario->com_id)->where('cal_post',0)->where('cal_user',$user->nocontrol)->where('cal_calificacion',(!$calificacion));
        if ($califico->count() > 0)
            $califico->delete();


        try {
            Calificacion::create([
                'cal_id' => $comentario->com_id,
                'cal_post' => 0,
                'cal_user' => $user->nocontrol,
                'cal_calificacion' => $calificacion
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'Mensaje' => 'Calificado correctamente'
            ]);
        }

        return response()->json([
            'Mensaje' => 'Calificado correctamente'
        ]);
    }
}
