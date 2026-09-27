@extends('errors.layout')

@section('code', '401')
@section('title', 'Nicht autorisiert')
@section('message')
    {{ $exception->getMessage() !== '' && $exception->getMessage() !== 'Unauthorized'
        ? $exception->getMessage()
        : \App\Support\FormOfAddress::choose('Du bist nicht berechtigt, diese Seite aufzurufen.', 'Sie sind nicht berechtigt, diese Seite aufzurufen.') }}
@endsection
