@extends('errors.layout')

@section('code', (string) $exception->getStatusCode())
@section('title', 'Anfrage nicht möglich')
@section('message')
    {{ $exception->getMessage() !== '' ? $exception->getMessage() : 'Die Anfrage konnte nicht verarbeitet werden.' }}
@endsection
