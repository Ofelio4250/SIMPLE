<?php

class Proceso extends Doctrine_Record {

    function setTableDefinition() {
        $this->hasColumn('id');
        $this->hasColumn('nombre');
        $this->hasColumn('width');      //ancho de la grilla
        $this->hasColumn('height');     //alto de la grilla
        $this->hasColumn('cuenta_id');
        $this->hasColumn('proc_cont');
        $this->hasColumn('categoria_id');
        $this->hasColumn('destacado');
        $this->hasColumn('icon_ref');
        $this->hasColumn('activo');
        $this->hasColumn('version');
        $this->hasColumn('root');
        $this->hasColumn('estado');
    }

    function setUp() {
        parent::setUp();
        
        $this->hasOne('Cuenta',array(
            'local'=>'cuenta_id',
            'foreign'=>'id'
        ));
        
        $this->hasMany('Tramite as Tramites',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
        ));
        
        $this->hasMany('Tarea as Tareas',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
        ));
        
        $this->hasMany('Formulario as Formularios',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
            'orderBy'=>'nombre asc'
        ));
        
        $this->hasMany('Accion as Acciones',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
            'orderBy'=>'nombre asc'
        ));

        $this->hasMany('Seguridad as Admseguridad',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
            'orderBy'=>'institucion asc'
        ));

        $this->hasMany('Suscriptor as Suscriptores',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
            'orderBy'=>'institucion asc'
        ));

        $this->hasMany('Documento as Documentos',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
            'orderBy'=>'nombre asc'
        ));
        
        $this->hasMany('Reporte as Reportes',array(
            'local'=>'id',
            'foreign'=>'proceso_id',
            'orderBy'=>'nombre asc'
        ));
    }
    
    public function updateModelFromJSON($json){
        Doctrine_Manager::connection()->beginTransaction();
        $modelo = json_decode($json);

        //Agregamos los elementos nuevos y/o existentes
        foreach ($modelo->elements as $e) {
            $tarea = Doctrine::getTable('Tarea')->findOneByIdentificadorAndProcesoId($e->id, $this->id);
            $tarea->posx = $e->left;
            $tarea->posy = $e->top;
            $tarea->save();
        }

        Doctrine_Manager::connection()->commit();
        
    }
    
    public function getJSONFromModel(){
        Doctrine_Manager::connection()->beginTransaction();
        
        $modelo=new stdClass();
        $modelo->nombre=$this->nombre;
        $modelo->elements=array();
        $modelo->connections=array();
        
        $tareas=Doctrine::getTable('Tarea')->findByProcesoId($this->id);
        foreach($tareas as $t){
            $element=new stdClass();
            $element->id=$t->identificador;
            $element->name=$t->nombre;
            $element->left=$t->posx;
            $element->top=$t->posy;
            $element->start=$t->inicial;
            $element->externa=$t->externa;
            $element->stop=$t->final;
            $modelo->elements[]=clone $element;
        }
        
        $conexiones=  Doctrine_Query::create()
                ->from('Conexion c, c.TareaOrigen.Proceso p')
                ->where('p.activo=1 AND p.id = ?',$this->id)
                ->execute();
        foreach($conexiones as $c){
            //$conexion->id=$c->identificador;
            $conexion=new stdClass();
            $conexion->source=$c->TareaOrigen->identificador;
            $conexion->target=$c->TareaDestino->identificador;
            $conexion->tipo=$c->tipo;
            $modelo->connections[]=clone $conexion;
        }
        
        Doctrine_Manager::connection()->commit();
        
        return json_encode($modelo);
    }

    public function getConexiones(){
        /*return Doctrine_Query::create()
            ->select('c.*')
            ->from('Conexion c, c.TareaOrigen.Proceso p1, c.TareaDestino.Proceso p2')
            ->where('(p1.activo=1 AND p2.activo=1) AND (p1.id = ? OR p2.id = ?)',array($this->id,$this->id))
            ->execute();*/


        return Doctrine_Query::create()
            ->select('c.*')
            ->from('Conexion c, c.TareaOrigen.Proceso p1')
            ->where('p1.activo=1 AND p1.id = ?',array($this->id))
            ->execute();
    }

    public function exportComplete(){
        $proceso=$this;
        $proceso->Tareas;
        foreach($proceso->Tareas as $t){
            $t->Pasos;
            $t->Eventos;
            $t->EventosExternos;
        }

        $proceso->Formularios;
        foreach ($proceso->Formularios as $f) {
            $f->Campos;
        }

        $proceso->Acciones;
        $proceso->Documentos;
        $proceso->Admseguridad;
        $proceso->Suscriptores;

        $object=$proceso->toArray();        
        $object['Conexiones']=$proceso->getConexiones()->toArray();

        return json_encode($object);

    }

    /**
     * @param $input
     * @return Proceso
     */
    public static function importComplete($input, $isImport = FALSE) {
        $json=json_decode($input);

        //Creamos el proceso
        $proceso=new Proceso();
        $proceso->cuenta_id = UsuarioBackendSesion::usuario()->cuenta_id;

        //Creamos los documentos
        foreach($json->Documentos as $f) {
            $proceso->Documentos[$f->id]=new Documento();
            foreach($f as $keyf => $f_attr) {
                if($keyf != 'id' && $keyf != 'proceso_id' && $keyf != 'Proceso' && $keyf != 'hsm_configuracion_id'){
                    $proceso->Documentos[$f->id]->{$keyf}=$f_attr;
                }
            }

        }

        //Creamos los formularios
        foreach($json->Formularios as $f){
            $proceso->Formularios[$f->id]=new Formulario();
            foreach($f as $keyf => $f_attr)
                if($keyf == 'Campos'){
                    foreach($f_attr as $c){
                        $campo = new Campo();
                        foreach($c as $keyc => $c_attr){
                            if($keyc != 'id' && $keyc != 'formulario_id' && $keyc != 'Formulario' && $keyc != 'documento_id'){
                                $campo->{$keyc} = $c_attr;
                            }
                        }
                        if($c->documento_id) $campo->Documento = $proceso->Documentos[$c->documento_id];
                        $proceso->Formularios[$f->id]->Campos[]=$campo;
                    }
                }elseif($keyf != 'id' && $keyf != 'proceso_id' && $keyf != 'Proceso'){
                    $proceso->Formularios[$f->id]->{$keyf}=$f_attr;
                }

        }

        log_message('info','Se crean acciones de nuevo proceso importado', FALSE);
        // Creamos las acciones
        foreach ($json->Acciones as $f) {
            $proceso->Acciones[$f->id] = new Accion();
            foreach ($f as $keyf => $f_attr) {
                if ($keyf != 'id' && $keyf != 'proceso_id' && $keyf != 'Proceso') {
                    $proceso->Acciones[$f->id]->{$keyf} = $f_attr;
                }
            }
        }
        log_message('info','Acciones creadas', FALSE);

        //Completamos el proceso y sus tareas
        foreach ($json as $keyp=>$p_attr) {
            if ($keyp == 'Tareas') {
                foreach ($p_attr as $t) {
                    $tarea = new Tarea();
                    foreach ($t as $keyt=>$t_attr) {
                        log_message("info", "Verificando keyt: ".$keyt, FALSE);
                        if ($keyt == 'Pasos') {
                            foreach ($t_attr as $pa) {
                                $paso = new Paso();
                                foreach ($pa as $keypa => $pa_attr) {
                                    if ($keypa != 'id' && $keypa != 'tarea_id' && $keypa != 'Tarea' && $keypa != 'formulario_id')
                                        $paso->{$keypa} = $pa_attr;
                                }
                                $paso->Formulario = $proceso->Formularios[$pa->formulario_id];
                                $tarea->Pasos[$pa->id] = $paso;
                            }
                        } elseif ($keyt == 'Eventos') {
                            foreach ($t_attr as $ev) {
                                $evento = new Evento();
                                foreach ($ev as $keyev => $ev_attr) {
                                    if ($keyev != 'id' && $keyev != 'tarea_id' && $keyev != 'Tarea' && $keyev != 'accion_id' && $keyev != 'paso_id')
                                        $evento->{$keyev} = $ev_attr;
                                }
                                $evento->Accion = $proceso->Acciones[$ev->accion_id];
                                if ($ev->paso_id)$evento->Paso = $tarea->Pasos[$ev->paso_id];
                                $tarea->Eventos[] = $evento;
                            }
                        } elseif ($keyt == 'EventosExternos') {
                            log_message("info", "Agregando eventos externos", FALSE);
                            foreach ($tarea->EventosExternos as $key => $val)
                                unset($tarea->EventosExternos[$key]);
                            foreach ($t_attr as $ev) {
                                $evento_externo = new EventoExterno();
                                foreach ($ev as $keyev => $ev_attr) {
                                    if ($keyev != 'id' && $keyev != 'tarea_id' && $keyev != 'Tarea') {
                                        $evento_externo->{$keyev} = $ev_attr;
                                    }
                                    log_message("info", "evento a agregar: ", FALSE);
                                    log_message("info", "Id: ".$evento_externo->id, FALSE);
                                    log_message("info", "nombre: ".$evento_externo->nombre, FALSE);
                                    log_message("info", "metodo: ".$evento_externo->metodo, FALSE);
                                    log_message("info", "url: ".$evento_externo->url, FALSE);
                                    log_message("info", "mensaje: ".$evento_externo->mensaje, FALSE);
                                    log_message("info", "regla: ".$evento_externo->regla, FALSE);
                                    log_message("info", "tarea_id: ".$evento_externo->tarea_id, FALSE);
                                    log_message("info", "opciones: ".$evento_externo->opciones, FALSE);
                                    $tarea->EventosExternos[$ev->id] = $evento_externo;
                                }
                            }

                            log_message("info", "Eventos externos agregados: ".count($tarea->EventosExternos), FALSE);

                        } elseif ($keyt != 'id' && $keyt != 'proceso_id' && $keyt != 'Proceso') { // && $keyt != 'grupos_usuarios'){
                            $tarea->{$keyt} = $t_attr;
                        }
                    }

                    $proceso->Tareas[$t->id] = $tarea;
                }
            } elseif ($keyp == 'Formularios' || $keyp == 'Acciones' || $keyp == 'Documentos' || $keyp == 'Conexiones' || $keyp == 'Admseguridad' || $keyp == 'Suscriptores') {
            
            } elseif ($keyp != 'id' && $keyp != 'cuenta_id' && $isImport == FALSE) {

                log_message('debug', '$keyp [' . $keyp . '] $p_attr [' . $p_attr . '] $isImport [' . $isImport . ']');
                $proceso->{$keyp} = $p_attr;

            } elseif ($keyp != 'id' && $keyp != 'cuenta_id' && $isImport == TRUE) {

                log_message('debug', '$keyp [' . $keyp . '] $p_attr [' . $p_attr . '] $isImport [' . $isImport . ']');

                if ($keyp == 'nombre') {
                    $fecha = new Datetime();
                    $proceso->{$keyp} = $p_attr . ' (Importación: ' .  $fecha->format("d-m-Y H:i:s") . ')';
                } elseif ($keyp == 'root') {
                    $proceso->{$keyp} = null;
                } elseif ($keyp == 'version') {
                    $proceso->{$keyp} = 1;
                } else {
                    $proceso->{$keyp} = $p_attr;
                }
            }
        }

        // Hacemos las conexiones
        foreach ($json->Conexiones as $c) {
            $conexion = new Conexion();
            $proceso->Tareas[$c->tarea_id_origen]->ConexionesOrigen[] = $conexion;
            if ($c->tarea_id_destino) $proceso->Tareas[$c->tarea_id_destino]->ConexionesDestino[] = $conexion;
            foreach ($c as $keyc => $c_attr){
                if($keyc!='id' && $keyc != 'tarea_id_origen' && $keyc != 'tarea_id_destino'){
                    $conexion->{$keyc} = $c_attr;
                }
            }
        }

        log_message('info', 'Conexiones creadas', FALSE);

        //Creamos las configuraciones de seguridad
        foreach($json->Admseguridad as $f){
            log_message('info','Admseguridad id: '.$f->id, FALSE);
            $proceso->Admseguridad[$f->id] = new Seguridad();
            log_message('info','Completando', FALSE);
            foreach ($f as $keyf => $f_attr) {
                if ($keyf != 'id' && $keyf != 'proceso_id' && $keyf != 'Proceso') {
                    $proceso->Admseguridad[$f->id]->{$keyf} = $f_attr;
                }
            }
        }
        log_message('info','Seguridad creadas', FALSE);

        //Creamos las configuraciones de suscriptores
        foreach($json->Suscriptores as $f){
            $proceso->Suscriptores[$f->id]=new Suscriptor();
            foreach($f as $keyf => $f_attr){
                if($keyf != 'id' && $keyf != 'proceso_id' && $keyf != 'Proceso'){
                    $proceso->Suscriptores[$f->id]->{$keyf}=$f_attr;
                }
            }
        }
        log_message('info','Suscriptores creados', FALSE);

        return $proceso;


    }


    //Entrega la tarea inicial del proceso. Si se entrega $usuario_id, muestra cual seria la tarea inicial para
    //ese usuario en particular.
    public function getTareaInicial($usuario_id=null){
        $tareas=Doctrine_Query::create()
                ->from('Tarea t, t.Proceso p')
                ->where('t.inicial = 1 AND p.activo=1 AND p.id = ?',$this->id)
                ->orderBy('FIELD(acceso_modo, "grupos_usuarios", "claveunica", "registrados", "publico")')
                ->execute();

        if($usuario_id){
            foreach($tareas as $key=>$t)
                if ($t->canUsuarioIniciarla($usuario_id))
                    return $t;
        }

        return $tareas[0];

    }
    
    //Obtiene todos los campos asociados a este proceso
    public function getCampos($tipo=null,$excluir_readonly=true){
        $query= Doctrine_Query::create()
                ->from('Campo c, c.Formulario f, f.Proceso p')
                ->where('p.activo=1 AND p.id = ?',$this->id);
        
        if($tipo)
            $query->andWhere('c.tipo = ?',$tipo);
        
        if($excluir_readonly)
            $query->andWhere('c.readonly = 0');
        
        return $query->execute();
    }
    
    //Obtiene todos los campos asociados a este proceso
    public function getNombresDeCampos($tipo=null,$excluir_readonly=true){
        $campos=$this->getCampos($tipo,$excluir_readonly);
        
        $nombres=array();
        foreach($campos as $c)
            $nombres[$c->nombre]=true;
        
        return array_keys($nombres);
    }
    
    // Obtiene las variables asociadas a este proceso
    public function getVariables(){
    	return  Doctrine_Query::create()
    	->from('Accion a, a.Proceso p')
    	->where('p.activo=1 AND p.id=?', $this->id)
    	->andWhere("tipo = 'variable'")
    	->execute();
    	
    	
    }
    
    
    //Retorna una arreglo con todos los nombres de datos
    public function getNombresDeDatos(){
    	
    		$campos=Doctrine_Query::create()
    		->select('d.nombre')
    		->from('DatoSeguimiento d, d.Etapa.Tramite.Proceso p')
    		->andWhere('p.activo=1 AND p.id = ?',$this->id)
    		->groupBy('d.nombre')
    		->execute();
    	
    		foreach($campos as $c)
    			$result[]=$c->nombre;
    	
    		return $result;

    }
    
    //Verifica si el usuario_id tiene permisos para iniciar este proceso como tramite.
    public function canUsuarioIniciarlo($usuario_id){

        $tareas = Doctrine_Query::create()
                ->from('Tarea t, t.Proceso p')
                ->where('p.activo=1 AND p.id = ? AND t.inicial = 1',$this->id)
                ->execute();

        foreach ($tareas as $t) {
            if($t->canUsuarioIniciarla($usuario_id))
                return true;
        }
        
        
        return false;
    }
    
    //Verifica si el usuario_id tiene permisos para que le aparezca listado en las bandejas del frontend
    public function canUsuarioListarlo($usuario_id){
        $usuario=Doctrine::getTable('Usuario')->find($usuario_id);
        
        $tareas = Doctrine_Query::create()
                ->from('Tarea t, t.Proceso p')
                ->where('p.activo=1 AND p.id = ? AND t.inicial = 1',$this->id)
                ->execute();

        foreach ($tareas as $t) {
            if($t->acceso_modo=='publico')
                return true;

            if ($t->acceso_modo == 'claveunica')
                return true;

            if ($t->acceso_modo == 'registrados')
                return true;

            if ($t->acceso_modo == 'grupos_usuarios') {
                $grupos_arr = explode(',', $t->grupos_usuarios);
                $u = Doctrine_Query::create()
                        ->from('Usuario u, u.GruposUsuarios g')
                        ->where('u.id = ?', $usuario->id)
                        ->andWhereIn('g.id', $grupos_arr)
                        ->fetchOne();
                if ($u)
                    return true;
            }
            
        }
        
        return false;
    }
    
    
    public function toPublicArray(){
        $publicArray=array(
            'id'=>(int)$this->id,
            'nombre'=>$this->nombre,
            'version'=>$this->version
        );
        
        return $publicArray;
    }
    
    
    public function getVariablesReporteHeaders(){
    	

    	$variables = $this->getVariables();
    	
    	foreach($variables as $v){
    		 
    		 
    		$result[]=$v->extra->variable . ' - '.$v->nombre;
    		 
    	}
    	return $result;
    }

    public function getCamposReporteHeaders() {

    	$campos = $this->getCampos();

    	foreach ($campos as $c) {
            if ($c->tipo == 'maps') {
                $result[] = $c->nombre . ' - ' . $c->etiqueta;
                $result[] = $c->nombre . '->latitude - ' . $c->etiqueta;
                $result[] = $c->nombre . '->longitude - ' . $c->etiqueta;
                $result[] = $c->nombre . '->address - ' . $c->etiqueta;
            } else {
                $result[] = $c->nombre . ' - ' . $c->etiqueta;
            }
        }
    	return $result;
    }
    
    public function getTramitesCompletos(){
    	
    	return Doctrine_Query::create()
    		->from("Tramite t")
    		->where("t.proceso_id = ?", $this->id)
    		->andWhere("t.ended_at IS NOT NULL")
    		->fetchOne(null, Doctrine::HYDRATE_ARRAY);
    	
    	
    }
   
    
    public function getDiasPorTramitesAvg(){
    	
    	return Doctrine_Query::create()
    		->select("AVG(DATEDIFF(ended_at,created_at)) as avg")
    		->from("Tramite t")
    		->where("t.proceso_id = ?", $this->id)
    		->andWhere("t.ended_at IS NOT NULL")
    		->execute();
    }

    // Elimina los procesos de manera logica
    public function delete_logico($proceso_id) {

        log_message('info', 'delete test ($proceso_id [' . $proceso_id . '])');

        return Doctrine_Query::create()
            ->update('Proceso')->set('activo', 0)
            ->where('id = ?', $proceso_id)
            ->execute();
    }

    public function findIdProcesoActivo($root, $cuenta_id) {

        $procesos = Doctrine_Query::create()
            ->from('Proceso p, p.Cuenta c')
            ->where('(p.root = ? OR p.id = ?) AND p.estado="public" AND c.id = ?', array($root, $root, $cuenta_id))
            ->execute();

        return $procesos[0];
    }

    public function findProcesosByRoot($root, $cuenta_id) {

        $procesos = Doctrine_Query::create()
            ->from('Proceso p, p.Cuenta c')
            ->where('(p.root = ? OR p.id = ?) AND c.id = ?', array($root, $root, $cuenta_id))
            ->execute();

        return $procesos;
    }

    public function findProcesosArchivados($root){
        log_message('Info', 'Buscando archivados para proceso root: '.$root);

        $procesos = Doctrine_Query::create()
            ->from('Proceso p')
            ->where('(p.root = ? OR p.id = ?)', array($root, $root))
            ->orderBy('p.version desc')
            ->execute();

        log_message('Info', 'Se ejecuta query procesos archivados');

        $data = array();
        foreach ($procesos as $proceso_rel){
            $data[] = array(
                "id" => $proceso_rel->id,
                "nombre" => $proceso_rel->nombre.'-'.$proceso_rel->estado,
                "version" => $proceso_rel->version
            );
        }
        return $data;
    }

    public function findDraftProceso($root, $cuenta_id){

        $draft = Doctrine_Query::create()
            ->from('Proceso p, p.Cuenta c')
            ->where('(p.root = ? OR p.id = ?) AND p.estado="draft" AND c.id = ?', array($root, $root, $cuenta_id))
            ->execute();

        return $draft[0];
    }

    public function findMaxVersion($root, $cuenta_id){

        $sql = "select MAX(p.version) as version from proceso p where p.cuenta_id = $cuenta_id and (p.root = $root or p.id = $root);";

        $stmn = Doctrine_Manager::getInstance()->connection();
        $result = $stmn->execute($sql)
            ->fetchAll();
        return $result[0]['version'];
    }

    public function getTareasProceso(){
        $tareas=Doctrine_Query::create()
            ->from('Tarea t, t.Proceso p')
            ->where('p.id = ?',$this->id)
            ->execute();

        return $tareas;

    }

    static function varDump($data){
        ob_start();
        //var_dump($data);
        print_r($data);
        $ret_val = ob_get_contents();
        ob_end_clean();
        return $ret_val;
    }

}
