<?php

namespace App\Http\Controllers\Facturacion;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Inventario\Articulo;
use App\Models\Usuarios\User;
use App\Models\Cartera\Deuda;
use App\Models\Facturacion\Factura;
use App\Models\Facturacion\FacturaProducto;
use App\Http\Controllers\Facturacion\MetodoDePago;
use Carbon\Carbon;

class CompraProducto extends Controller
{
  public function index(){
    return view('Facturacion.compra')->with('id_cliente',$id_cliente);
  }

  public function imprimirFactura(Request $request) {
    $id_cliente = (int)$request->id_cliente;
    $id_vendedor = (int)$request->idVendedor;
    $cliente = User::find($id_cliente);
    $cuota = (int)$request->cuota_credito;
    $fecha = Carbon::now('America/Bogota');
    $lista_productos = $request->lista;
    $total = (int)$request->total;

    if ($request->plan_pago == 'Efectivo'){
      $metodo = 1;
      $valorTotal = MetodoDePago::compraEfectivo($total);
      $valorCuota = 0;
      $valorPagar = 0;
      $estado = "cancelado";
    } else {

      if ($request->plan_pago == 'Credito'){

        $obj = MetodoDePago::compraCredito($id_cliente, $total, $cuota);

        if ($obj == []) {
          return view('Facturacion.error')->with('error', "El cliente no posee credito suficiente");
        }

        $valorPagar = $obj["valorPagar"];
        $valorTotal = $obj["valorCompra"];
        $valorCuota = $obj["valorCuota"];

        $estado = "pendiente";
        if ($request->cuota_credito == '1') $metodo = 2;
        if ($request->cuota_credito == '3') $metodo = 3;
        if ($request->cuota_credito == '6') $metodo = 4;

      }
    }

    if ($lista_productos[0] != null){

      $datos_factura = [
        'fecha' => $fecha,
        'id_cliente' => $id_cliente,
        'id_plan_pago' => $metodo,
        'cuotas' => $cuota,
        'valor_cuota' => $valorCuota,
        'id_vendedor' => $id_vendedor,
        'valor_total' => $valorTotal,
        'estado' => $estado
        ];

      $factura = Factura::create($datos_factura);

      $lista_productos = preg_split("/[,]+/", $lista_productos[0]);


      for ($i = 0; $i < count($lista_productos); $i++) {
        if(($i%7) == 0){
          $req = new Request();
          $req->id_factura = $factura->id;
          $req->id_articulo = $lista_productos[$i];
          $req->cantidad = $lista_productos[$i+2];
          $req->precio_venta = $lista_productos[$i+3];
          $req->pendiente = $lista_productos[$i+5];
          self::insertFacturaProducto($req);

          $producto = Articulo::where("id", $lista_productos[$i])->get();
          $producto[0]->cantidad = $producto[0]->cantidad - (int)$lista_productos[$i+2];
          $producto[0]->save();
        }
      }

      if ($request->plan_pago == 'Credito') {

        $datos_deuda = [
          'id_usuario' => $id_cliente,
          'id_plan' => $metodo,
          'id_factura' => $factura->id,
          'valor_pagado' => (int)$valorPagar,
          'valor_a_pagar' => (int)$valorTotal,
          'plazo_credito' => $fecha->addMonths($cuota),
          'estado' => "pendiente"
        ];

        //dd($datos_deuda);
        $deuda = Deuda::create($datos_deuda);
        }

      return view('Facturacion.factura')->with('fecha',$fecha->format('d-M-Y'))
                                        ->with('idFactura',$factura->id)
                                        ->with('lista_productos',$lista_productos)
                                        ->with('total',$valorTotal)
                                        ->with('pagado',$valorPagar)
                                        ->with('id_cliente',$id_cliente)
                                        ->with('nombre_cliente',$cliente->name)
                                        ->with('plan_pago',$request->plan_pago)
                                        ->with('cuota_credito',$cuota);
    } else {
      return view('Facturacion.error')->with('error', "No se han agregado productos a la factura");
    }
  }

  public function insertFacturaProducto(Request $req) {
    // if req is not null
    $new = new FacturaProducto;
    $new->id_factura = $req->id_factura;
    $new->id_articulo = $req->id_articulo;
    $new->cantidad = $req->cantidad;
    $new->precio_venta = $req->precio_venta;
    $new->pendiente = $req->pendiente;

    $new->save();
  }

  public function precioVenta($id_producto){

    $Producto = Articulo::where("id", $id_producto)->get();
      $PrecioBase = $Producto[0]->precio_basico;
      $PrecioProducto = $PrecioBase + ($PrecioBase*0.25);
      return $PrecioProducto;
  }

  public function compra($cantidad, $id_producto){
    $Producto = Articulo::where("id", $id_producto)->get();
    if (count($Producto) >0){
      //dd($Producto[0]->nombre);
      if ($Producto[0]->cantidad < 0) {
        $pendiente =  - $cantidad;
      }
      else{

        $pendiente = $Producto[0]->cantidad - $cantidad;
      }
      $descripcion = $Producto[0]->descripcion;
      $valorVenta = self::precioVenta($id_producto, $Producto[0]->precio_basico);
      $unitario = $valorVenta;
      $total = $valorVenta * $cantidad;

      if ($pendiente > 0) {
        $pendiente = 0;
      }

      return response()->json(array(
        'id_producto' => $id_producto,
        'descripcion' => $descripcion,
        'cantidad' => $cantidad,
        'unitario' => $valorVenta,
        'total' => $total,
        'pendiente' => $pendiente,
        'cantidadInventario' => $Producto[0]->cantidad,
      ));
    }
    return "false";
  }
}
