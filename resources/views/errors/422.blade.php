@extends('errors.layout')

@section('code', '422')
@section('title', 'Anfrage nicht verarbeitbar')
@section('message')
    {{ $exception->getMessage() !== '' && $exception->getMessage() !== 'Unprocessable Content'
        ? $exception->getMessage()
        : 'Die übermittelten Angaben konnten nicht verarbeitet werden.' }}
@endsection
