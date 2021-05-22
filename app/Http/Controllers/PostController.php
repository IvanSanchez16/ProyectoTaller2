<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalificarRequest;
use App\Http\Requests\ComentarioRequest;
use App\Http\Requests\ForoRequest;
use App\Http\Requests\PostRequest;
use App\Models\Archivo;
use App\Models\Calificacion;
use App\Models\Comentario;
use App\Models\Desccomentarios;
use App\Models\Descpost;
use App\Models\MaterialPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{

    public function index() {
        $posts = $this->getPosts(true);

        return response()->json($posts);
    }

    public function indexForo(){
        $posts = $this->getPosts(false);

        return response()->json($posts);
    }

    private function getPosts($band){
        $user = Auth::user();
        if ($band)
            $posts = Post::select([
                'post_id as id',
                DB::raw('(CONCAT(users.nombre," ",users.apellido_paterno)) as nombre'),
                'post_user as user',
                'post_subtitle as subtitulo',
                'mat_nombre as materia',
                DB::raw('(DATE_FORMAT(posts.created_at,"%d/%m/%Y")) as fecha')
            ])
                ->join('users','nocontrol','=','post_user')
                ->join('materias','mat_id','=','post_materia')
                ->join('materialposts','mat_post','=','post_id')
                ->get();
        else
            $posts = Post::select([
                'post_id as id',
                DB::raw('(CONCAT(users.nombre," ",users.apellido_paterno)) as nombre'),
                'post_subtitle as subtitulo',
                'mat_nombre as materia',
                DB::raw('(DATE_FORMAT(posts.created_at,"%d/%m/%Y")) as fecha')
            ])
                ->join('users','nocontrol','=','post_user')
                ->join('materias','mat_id','=','post_materia')
                ->leftJoin('materialposts','mat_post','=','post_id')
                ->whereNull('mat_post')
                ->get();

        foreach ($posts as $post){
            //Texto
            $post['texto'] = $post->getText();

            //Comentarios
            $numComentarios = Comentario::where('com_post',$post->id)->count();
            $post['numComentarios'] = $numComentarios;

            //Calificacion
            $votosBuenos = Calificacion::where('cal_id',$post->id)->where('cal_post',1)->where('cal_calificacion',1)->count();
            $votosMalas = Calificacion::where('cal_id',$post->id)->where('cal_post',1)->where('cal_calificacion',0)->count();
            $votoPropio = Calificacion::where('cal_id',$post->id)->where('cal_post',1)->where('cal_user',$user->nocontrol)->first();
            $votoPropio = !$votoPropio ? 1 : ($votoPropio->cal_calificacion == 1 ? 2 : 0);

            $post['calificaciones'] = [
                'votosBuenos' => $votosBuenos,
                'votosMalos' => $votosMalas,
                'votoPropio' => $votoPropio
            ];

            //Autor foto
            $autor = User::find($post['user']);
            $post['fotoAutor'] = $autor->getURLFoto();
            unset($post['user']);

            //Archivos
            if ($band){
                $archivos = Archivo::select('arch_id as id','arch_nombre as nombre')->join('materialposts','mat_arch','=','arch_id')->where('mat_post',$post->id)->get();
                $post['archivos'] = $archivos;
            }
        }
        return $posts;
    }

    public function store(PostRequest $request) {
        $response = $this->savePost($request);
        if ($response)
            return response()->json([
                'Mensaje' => 'Post creado correctamente'
            ],201);
        else
            return response()->json([
                'Mensaje' => 'Error al crear el post'
            ],500);
    }

    public function storeForo(ForoRequest $request){
        $response = $this->savePost($request);
        if ($response)
            return response()->json([
                'Mensaje' => 'Post creado correctamente'
            ],201);
        else
            return response()->json([
                'Mensaje' => 'Error al crear el post'
            ],500);
    }

    private function savePost($request){
        $user = Auth::user();

        if ($request->exists('archivos')){
            $archivos = $request->get('archivos');
            foreach ($archivos as $archivo){
                $aux = Archivo::find($archivo);

                if ( $aux->arch_privado == 1 )
                    return response()->json([
                        'Mensaje' => 'No puedes postear archivos privados'
                    ],400);
                if ( $aux->arch_user != $user->nocontrol )
                    return response()->json([
                        'Mensaje' => 'No puedes postear archivos que no son tuyos'
                    ],400);
            }

        }

        $post = Post::create([
            'post_user' => $user->nocontrol,
            'post_subtitle' => $request->get('subtitulo'),
            'post_materia' => $request->get('materia')
        ]);

        $texto = $request->get('texto');
        if (strlen($texto) <= 255)
            Descpost::create([
                'dpost_post' => $post->post_id,
                'dpost_inc' => 1,
                'descripcion' => $texto
            ]);
        else {
            $inc = 1;
            do {
                $largo = strlen($texto) >= 255 ? 255 : strlen($texto);
                $textoAux = substr($texto,0,$largo);
                Descpost::create([
                    'dpost_post' => $post->post_id,
                    'dpost_inc' => $inc++,
                    'descripcion' => $textoAux
                ]);
                $texto = substr($texto,$largo);
            }while( strlen($texto)>0 );
        }

        if ($request->exists('archivos')){
            foreach ($archivos as $archivo)
                MaterialPost::create([
                    'mat_post' => $post->post_id,
                    'mat_arch' => $archivo
                ]);
        }

        return true;
    }

    public function calificar(CalificarRequest $request,Post $post) {
        $user = Auth::user();
        if ($user->nocontrol == $post->post_user)
            return response()->json([
                "Error" => 'No puedes votar tus propios posts o comentarios'
            ],401);

        $calificacion = $request->get('voto');
        $calificacion = $calificacion == "1";
        $califico = Calificacion::where('cal_id',$post->post_id)->where('cal_post',1)->where('cal_user',$user->nocontrol)->where('cal_calificacion',(!$calificacion));
        if ($califico->count() > 0)
            $califico->delete();


        try {
            Calificacion::create([
                'cal_id' => $post->post_id,
                'cal_post' => 1,
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

    public function comentar(ComentarioRequest $request,Post $post){
        $user = Auth::user();
        if ( $request->exists('comentario') )
            $comentario = Comentario::create([
                'com_user' => $user->nocontrol,
                'com_post' => $post->post_id,
                'com_comentario' => $request->get('comentario')
            ]);
        else
            $comentario = Comentario::create([
                'com_user' => $user->nocontrol,
                'com_post' => $post->post_id,
            ]);
        //Guardar texto
        $texto = $request->get('texto');
        if (strlen($texto) <= 255)
            Desccomentarios::create([
                'dcom_comentario' => $comentario->com_id,
                'dcom_inc' => 1,
                'descripcion' => $texto
            ]);
        else {
            $inc = 1;
            do {
                $largo = strlen($texto) >= 255 ? 255 : strlen($texto);
                $textoAux = substr($texto,0,$largo);
                Desccomentarios::create([
                    'dcom_comentario' => $comentario->com_id,
                    'dcom_inc' => $inc++,
                    'descripcion' => $textoAux
                ]);
                $texto = substr($texto,$largo);
            }while( strlen($texto)>0 );
        }

        return response()->json([
            'Mensaje' => 'Comentario registrado correctamente'
        ],201);
    }

    public function show(Post $post) {
        $user = Auth::user();
        $postDetails = Post::select([
            'post_id as id',
            DB::raw('(CONCAT(users.nombre," ",users.apellido_paterno)) as nombre'),
            'post_subtitle as subtitulo',
            'mat_nombre as materia',
            'post_user as user',
            DB::raw('(DATE_FORMAT(posts.created_at,"%d/%m/%Y")) as fecha')
        ])
            ->join('users','nocontrol','=','post_user')
            ->join('materias','mat_id','=','post_materia')
            ->where('post_id',$post->post_id)
            ->first();

        //Texto
        $postDetails['texto'] = $post->getText();

        //Comentarios
        $Comentarios = $post->Comentarios();
        $postDetails['Comentarios'] = $Comentarios;

        //Calificacion
        $votosBuenos = Calificacion::where('cal_id',$postDetails->id)->where('cal_post',1)->where('cal_calificacion',1)->count();
        $votosMalas = Calificacion::where('cal_id',$postDetails->id)->where('cal_post',1)->where('cal_calificacion',0)->count();
        $votoPropio = Calificacion::where('cal_id',$postDetails->id)->where('cal_post',1)->where('cal_user',$user->nocontrol)->first();
        $votoPropio = !$votoPropio ? 1 : ($votoPropio->cal_calificacion == 1 ? 2 : 0);

        //Autor foto
        $autor = User::find($postDetails['user']);
        $postDetails['fotoAutor'] = $autor->getURLFoto();
        unset($postDetails['user']);

        $postDetails['calificaciones'] = [
            'votosBuenos' => $votosBuenos,
            'votosMalos' => $votosMalas,
            'votoPropio' => $votoPropio
        ];

        //Archivos
        $archivos = Archivo::select('arch_id as id','arch_nombre as nombre')->join('materialposts','mat_arch','=','arch_id')->where('mat_post',$postDetails->id)->get();
        if (count($archivos) > 0)
            $postDetails['archivos'] = $archivos;

        return response()->json($postDetails);
    }

    public function update(Request $request, Post $post) {
        //
    }

    public function destroy(Post $post) {
        //
    }
}
