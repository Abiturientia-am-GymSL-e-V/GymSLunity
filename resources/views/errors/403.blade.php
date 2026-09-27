@extends('errors.layout')

@section('code', '403')
@section('title', 'Zugriff verweigert')
@section('message')
    {{ $exception->getMessage() !== '' && ! in_array($exception->getMessage(), ['Forbidden', 'This action is unauthorized.'], true)
        ? $exception->getMessage()
        : \App\Support\FormOfAddress::choose('Du hast nicht die erforderliche Berechtigung für diese Seite.', 'Sie haben nicht die erforderliche Berechtigung für diese Seite.') }}
@endsection
