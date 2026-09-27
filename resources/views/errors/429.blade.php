@extends('errors.layout')

@section('code', '429')
@section('title', 'Zu viele Anfragen')
@section('message', \App\Support\FormOfAddress::choose('Bitte warte einen Moment, bevor du es erneut versuchst.', 'Bitte warten Sie einen Moment, bevor Sie es erneut versuchen.'))
