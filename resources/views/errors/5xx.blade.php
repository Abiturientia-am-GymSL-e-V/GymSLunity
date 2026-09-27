@extends('errors.layout')

@section('code', (string) $exception->getStatusCode())
@section('title', 'Technischer Fehler')
@section('message', \App\Support\FormOfAddress::choose('Bei der Verarbeitung ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.', 'Bei der Verarbeitung ist ein technischer Fehler aufgetreten. Bitte versuchen Sie es später erneut.'))
