#!/bin/bash
awslocal stepfunctions create-state-machine \
    --name "GracefulSchedulerStateMachine" \
    --definition file:///etc/localstack/init/ready.d/state-machine.json \
    --role-arn "arn:aws:iam::000000000000:role/stepfunctions-role"
