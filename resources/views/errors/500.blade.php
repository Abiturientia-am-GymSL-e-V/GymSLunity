@extends('errors.layout')

@section('code', '500')
@section('title', 'Interner Serverfehler')
@section('message', \App\Support\FormOfAddress::choose('Bei der Verarbeitung ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.', 'Bei der Verarbeitung ist ein technischer Fehler aufgetreten. Bitte versuchen Sie es später erneut.'))
